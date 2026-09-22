<?php

namespace RobertBoes\Patchbay\Filament\Resources;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use RobertBoes\Patchbay\Contracts\AppSource;
use RobertBoes\Patchbay\Filament\PatchbayPlugin;
use RobertBoes\Patchbay\Filament\Resources\AppResource\Pages;
use RobertBoes\Patchbay\Filament\Widgets;
use RobertBoes\Patchbay\Snippets;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Server\AppMetrics;
use RobertBoes\Patchbay\Server\ServerApi;

class AppResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModel(): string
    {
        return config('patchbay.model', App::class);
    }

    public static function getModelLabel(): string
    {
        return __('application');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Application'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->default(fn() => App::suggestName())
                        ->columnSpanFull(),

                    Toggle::make('active')
                        ->default(true)
                        ->helperText(__('Turning this off disconnects the application\'s clients.'))
                        ->columnSpanFull(),

                    TagsInput::make('allowed_origins')
                        ->placeholder('example.com')
                        ->helperText(__('Hostnames that may connect, such as example.com or *.example.com. Leave empty to allow any.'))
                        ->columnSpanFull(),
                ]),

            Section::make(__('Connection'))
                ->description(__('Left empty, the defaults from your Patchbay config are used.'))
                ->collapsed()
                ->columns(2)
                ->schema([
                    TextInput::make('ping_interval')
                        ->numeric()
                        ->minValue(1)
                        ->suffix(__('seconds'))
                        ->placeholder(config('patchbay.defaults.ping_interval')),

                    TextInput::make('activity_timeout')
                        ->numeric()
                        ->minValue(1)
                        ->suffix(__('seconds'))
                        ->placeholder(config('patchbay.defaults.activity_timeout')),

                    TextInput::make('max_message_size')
                        ->numeric()
                        ->minValue(1)
                        ->suffix(__('bytes'))
                        ->placeholder(config('patchbay.defaults.max_message_size')),

                    TextInput::make('max_connections')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(fn(?Model $record) => static::connectionLimit($record))
                        ->placeholder(fn(?Model $record) => static::connectionLimit($record) ?? __('unlimited'))
                        ->helperText(fn(?Model $record) => static::connectionLimit($record) === null
                            ? null
                            : __('Up to :limit.', ['limit' => static::connectionLimit($record)])),

                    Select::make('accept_client_events_from')
                        ->options([
                            'members' => __('Members of the channel'),
                            'all' => __('Any connected client'),
                            'none' => __('Nobody'),
                        ])
                        ->default('members')
                        ->helperText(__('Who may send events directly from the browser.'))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Credentials'))
                ->columns(2)
                ->schema([
                    TextEntry::make('id')
                        ->label(__('App ID'))
                        ->fontFamily(FontFamily::Mono)
                        ->copyable(),

                    TextEntry::make('key')
                        ->label(__('App key'))
                        ->fontFamily(FontFamily::Mono)
                        ->copyable(),

                    TextEntry::make('secret')
                        ->label(__('App secret'))
                        ->fontFamily(FontFamily::Mono)
                        ->columnSpanFull()
                        ->state(fn(Model $record, $livewire) => static::reveals($livewire)
                            ? $record->secret
                            : str_repeat('•', 40))
                        ->copyable(fn($livewire) => static::reveals($livewire)),
                ]),

            Section::make(__('Connect'))
                ->description(__('Paste into whatever connects. Reverb speaks the Pusher protocol, so any Pusher client works.'))
                ->schema([
                    Tabs::make()
                        ->contained(false)
                        ->tabs([
                            Tab::make(__('Laravel'))->schema([
                                static::snippetEntry('env', fn(Model $record) => app(Snippets::class)->env($record), holdsSecret: true),
                            ]),
                            Tab::make(__('Browser'))->schema([
                                static::snippetEntry('browser', fn(Model $record) => app(Snippets::class)->browser($record), holdsSecret: false),
                            ]),
                            Tab::make(__('Server'))->schema([
                                static::snippetEntry('server', fn(Model $record) => app(Snippets::class)->server($record), holdsSecret: true),
                            ]),
                        ]),
                ]),

            Section::make(__('Live'))
                ->description(__('Read from the running server.'))
                ->columns(2)
                ->schema([
                    TextEntry::make('connections')
                        ->label(__('Connections'))
                        ->state(fn(Model $record) => static::describe(
                            $record,
                            fn(AppMetrics $metrics) => (string) $metrics->connections,
                        )),

                    TextEntry::make('channels')
                        ->label(__('Open channels'))
                        ->state(fn(Model $record) => static::describe(
                            $record,
                            fn(AppMetrics $metrics) => implode(', ', $metrics->channelNames()) ?: __('None'),
                        )),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->emptyStateIcon('heroicon-o-signal')
            ->emptyStateHeading(__('No applications yet'))
            ->emptyStateDescription(__('An application is a key and secret for the WebSocket server. Create one and you can connect straight away.'))
            ->emptyStateActions([
                Action::make('create')
                    ->label(__('New application'))
                    ->url(fn() => static::getUrl('create'))
                    ->visible(fn() => static::canCreate()),
            ])
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('key')
                    ->label(__('App key'))
                    ->fontFamily(FontFamily::Mono)
                    // Enough to recognise, not enough to use.
                    ->formatStateUsing(fn(string $state) => substr($state, 0, 6) . str_repeat('•', 8)),

                IconColumn::make('active')
                    ->label(__('Active'))
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('active')
                    ->label(__('Status'))
                    ->placeholder(__('All'))
                    ->trueLabel(__('Active only'))
                    ->falseLabel(__('Inactive only')),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    static::activationAction(),
                    DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                BulkAction::make('deactivate')
                    ->label(__('Deactivate selected'))
                    ->icon('heroicon-m-pause-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(static::revokeWarning())
                    ->action(fn(Collection $records) => $records->each->update(['active' => false]))
                    ->deselectRecordsAfterCompletion(),

                DeleteBulkAction::make(),
            ]);
    }

    protected static function activationAction(): Action
    {
        return Action::make('activation')
            ->label(fn(Model $record) => $record->active ? __('Deactivate') : __('Activate'))
            ->icon(fn(Model $record) => $record->active ? 'heroicon-m-pause-circle' : 'heroicon-m-play-circle')
            ->color(fn(Model $record) => $record->active ? 'warning' : 'success')
            ->requiresConfirmation(fn(Model $record) => (bool) $record->active)
            ->modalDescription(fn(Model $record) => $record->active ? static::revokeWarning() : null)
            ->action(fn(Model $record) => $record->update(['active' => ! $record->active]));
    }

    public static function revokeWarning(): string
    {
        return __('Connected clients are disconnected and new connections are refused.');
    }

    public static function getWidgets(): array
    {
        return [Widgets\ServerStatus::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApps::route('/'),
            'create' => Pages\CreateApp::route('/create'),
            'view' => Pages\ViewApp::route('/{record}'),
            'edit' => Pages\EditApp::route('/{record}/edit'),
        ];
    }

    /**
     * @param  Closure(Model): string  $snippet
     */
    protected static function snippetEntry(string $name, Closure $snippet, bool $holdsSecret): TextEntry
    {
        $hidden = fn($livewire) => $holdsSecret && ! static::reveals($livewire);

        return TextEntry::make($name)
            ->hiddenLabel()
            ->fontFamily(FontFamily::Mono)
            ->state(fn(Model $record, $livewire) => $hidden($livewire)
                ? __('Reveal the secret to see this snippet.')
                : $snippet($record))
            // A <pre> of its own: line breaks and indentation are the content,
            // and the entry's template whitespace must stay outside it.
            ->formatStateUsing(fn(string $state, $livewire) => $hidden($livewire)
                ? $state
                : new HtmlString('<pre style="margin: 0; overflow-x: auto;">' . e($state) . '</pre>'))
            ->copyable(fn($livewire) => ! $hidden($livewire))
            ->copyableState(fn(Model $record) => $snippet($record));
    }

    protected static function connectionLimit(?Model $record): ?int
    {
        return PatchbayPlugin::current()?->getConnectionLimit($record);
    }

    protected static function reveals(mixed $livewire): bool
    {
        return $livewire instanceof Pages\ViewApp && $livewire->secretRevealed;
    }

    /**
     * @param  callable(AppMetrics): string  $present
     */
    protected static function describe(Model $record, callable $present): string
    {
        if (! $record->active) {
            return __('Inactive');
        }

        $application = app(AppSource::class)->loadById($record->getKey());

        if (! $application) {
            return __('Inactive');
        }

        $metrics = app(ServerApi::class)->metrics($application);

        if (! $metrics->available) {
            return __('Server unreachable');
        }

        return $present($metrics);
    }
}
