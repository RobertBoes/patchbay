<?php

namespace RobertBoes\Patchbay\Filament\Resources;

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
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RobertBoes\Patchbay\Contracts\AppSource;
use RobertBoes\Patchbay\EnvSnippet;
use RobertBoes\Patchbay\Filament\Resources\AppResource\Pages;
use RobertBoes\Patchbay\Filament\Widgets;
use RobertBoes\Patchbay\Server\AppMetrics;
use RobertBoes\Patchbay\Server\ServerApi;

class AppResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModel(): string
    {
        return config('patchbay.model', \RobertBoes\Patchbay\Models\App::class);
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
                        ->default(fn() => static::suggestName())
                        ->columnSpanFull(),

                    Toggle::make('active')
                        ->default(true)
                        ->helperText(__('Turning this off disconnects the application\'s clients.'))
                        ->columnSpanFull(),

                    TagsInput::make('allowed_origins')
                        ->placeholder('https://example.com')
                        ->helperText(__('Origins allowed to open a connection. Leave empty to allow any.'))
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
                        ->placeholder(__('unlimited')),

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

            Section::make(__('Environment'))
                ->description(__('Paste this into the consuming application.'))
                ->schema([
                    TextEntry::make('env')
                        ->hiddenLabel()
                        ->fontFamily(FontFamily::Mono)
                        ->state(fn(Model $record, $livewire) => static::reveals($livewire)
                            ? app(EnvSnippet::class)->for($record)
                            : __('Reveal the secret to see the full block.'))
                        ->copyable(fn($livewire) => static::reveals($livewire)),
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

    protected static function suggestName(): string
    {
        $adjectives = ['calm', 'bright', 'quiet', 'swift', 'warm', 'bold', 'clear'];
        $nouns = ['harbour', 'signal', 'meadow', 'beacon', 'river', 'summit', 'anchor'];

        return $adjectives[array_rand($adjectives)] . '-' . $nouns[array_rand($nouns)];
    }
}
