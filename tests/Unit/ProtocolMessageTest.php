<?php

namespace RobertBoes\Patchbay\Tests\Unit;

use RobertBoes\Patchbay\Metrics\ProtocolMessage;
use RobertBoes\Patchbay\Tests\TestCase;

class ProtocolMessageTest extends TestCase
{
    public function test_it_matches_what_the_protocol_sends_to_run_itself(): void
    {
        $this->assertTrue(ProtocolMessage::matches('{"event":"pusher:connection_established","data":{}}'));
        $this->assertTrue(ProtocolMessage::matches('{"event":"pusher:subscribe","data":{"channel":"test"}}'));
        $this->assertTrue(ProtocolMessage::matches('{"event":"pusher:unsubscribe","data":{"channel":"test"}}'));
        $this->assertTrue(ProtocolMessage::matches('{"event":"pusher:error","data":{}}'));
    }

    public function test_it_matches_the_messages_that_only_keep_a_connection_alive(): void
    {
        $this->assertTrue(ProtocolMessage::matches('{"event":"pusher:ping","data":{}}'));
        $this->assertTrue(ProtocolMessage::matches('{"event":"pusher:pong"}'));
    }

    public function test_it_matches_the_internal_replies_a_subscription_produces(): void
    {
        $this->assertTrue(ProtocolMessage::matches(
            '{"event":"pusher_internal:subscription_succeeded","data":"{}","channel":"test"}',
        ));

        $this->assertTrue(ProtocolMessage::matches(
            '{"event":"pusher_internal:member_added","data":"{}","channel":"presence-test"}',
        ));
    }

    public function test_it_leaves_what_an_application_sent_to_be_counted(): void
    {
        $this->assertFalse(ProtocolMessage::matches(
            '{"event":"OrderShipped","channel":"orders","data":{}}',
        ));

        $this->assertFalse(ProtocolMessage::matches(
            '{"event":"client-typing","channel":"private-chat","data":{}}',
        ));
    }

    public function test_an_event_is_not_protocol_because_it_mentions_it(): void
    {
        $this->assertFalse(ProtocolMessage::matches(
            '{"event":"client-chat","data":{"body":"pusher:subscribe"}}',
        ));

        $this->assertFalse(ProtocolMessage::matches(
            '{"event":"pusher-ish","data":{}}',
        ));
    }

    public function test_it_counts_anything_it_cannot_read_rather_than_discarding_it(): void
    {
        $this->assertFalse(ProtocolMessage::matches(''));
        $this->assertFalse(ProtocolMessage::matches('pusher:ping'));
        $this->assertFalse(ProtocolMessage::matches('{"event":"pusher:ping"'));
        $this->assertFalse(ProtocolMessage::matches('{"data":{}}'));
    }
}
