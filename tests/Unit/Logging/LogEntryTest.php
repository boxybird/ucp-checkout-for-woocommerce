<?php

/**
 * LogEntry Unit Tests
 *
 * Tests for the LogEntry value object.
 */

use UcpCheckout\Logging\LogEntry;

describe('LogEntry Value Object', function (): void {
    describe('Factory Methods', function (): void {
        it('creates a request log entry', function (): void {
            $entry = LogEntry::forRequest(
                requestId: 'req-123',
                endpoint: '/checkout-sessions',
                method: 'POST',
                agent: 'ChatGPT/1.0',
                ipAddress: '192.168.1.1',
                data: ['body' => ['line_items' => []]],
                sessionId: 'sess-456',
            );

            expect($entry->requestId)->toBe('req-123');
            expect($entry->type)->toBe(LogEntry::TYPE_REQUEST);
            expect($entry->endpoint)->toBe('/checkout-sessions');
            expect($entry->method)->toBe('POST');
            expect($entry->agent)->toBe('ChatGPT/1.0');
            expect($entry->ipAddress)->toBe('192.168.1.1');
            expect($entry->sessionId)->toBe('sess-456');
            expect($entry->data)->toBe(['body' => ['line_items' => []]]);
            expect($entry->createdAt)->toBeInstanceOf(DateTimeImmutable::class);
        });

        it('creates a response log entry', function (): void {
            $entry = LogEntry::forResponse(
                requestId: 'req-123',
                endpoint: '/checkout-sessions',
                method: 'POST',
                statusCode: 200,
                durationMs: 150,
                data: ['body' => ['id' => 'sess-456']],
                sessionId: 'sess-456',
            );

            expect($entry->requestId)->toBe('req-123');
            expect($entry->type)->toBe(LogEntry::TYPE_RESPONSE);
            expect($entry->statusCode)->toBe(200);
            expect($entry->durationMs)->toBe(150);
            expect($entry->sessionId)->toBe('sess-456');
        });

        it('creates a session event log entry', function (): void {
            $entry = LogEntry::forSession(
                requestId: 'req-123',
                sessionId: 'sess-456',
                event: 'status_changed',
                context: ['from' => 'incomplete', 'to' => 'ready_for_complete'],
            );

            expect($entry->type)->toBe(LogEntry::TYPE_SESSION);
            expect($entry->sessionId)->toBe('sess-456');
            expect($entry->data['event'])->toBe('status_changed');
            expect($entry->data['context'])->toBe(['from' => 'incomplete', 'to' => 'ready_for_complete']);
        });

        it('creates a payment event log entry', function (): void {
            $entry = LogEntry::forPayment(
                requestId: 'req-123',
                sessionId: 'sess-456',
                handler: 'stripe',
                success: true,
                error: null,
                context: ['order_id' => '789'],
            );

            expect($entry->type)->toBe(LogEntry::TYPE_PAYMENT);
            expect($entry->sessionId)->toBe('sess-456');
            expect($entry->data['handler'])->toBe('stripe');
            expect($entry->data['success'])->toBeTrue();
            expect($entry->data['error'])->toBeNull();
        });

        it('creates a payment failure log entry', function (): void {
            $entry = LogEntry::forPayment(
                requestId: 'req-123',
                sessionId: 'sess-456',
                handler: 'stripe',
                success: false,
                error: 'Card declined',
            );

            expect($entry->data['success'])->toBeFalse();
            expect($entry->data['error'])->toBe('Card declined');
        });

        it('creates an error log entry without trace', function (): void {
            $exception = new RuntimeException('Something went wrong', 500);

            $entry = LogEntry::forError(
                requestId: 'req-123',
                exception: $exception,
                endpoint: '/checkout-sessions/complete',
                sessionId: 'sess-456',
                includeTrace: false,
            );

            expect($entry->type)->toBe(LogEntry::TYPE_ERROR);
            expect($entry->endpoint)->toBe('/checkout-sessions/complete');
            expect($entry->sessionId)->toBe('sess-456');
            expect($entry->data['exception_type'])->toBe('RuntimeException');
            expect($entry->data['message'])->toBe('Something went wrong');
            expect($entry->data['code'])->toBe(500);
            expect($entry->data)->not->toHaveKey('trace');
        });

        it('creates an error log entry with trace when debug mode enabled', function (): void {
            $exception = new RuntimeException('Something went wrong');

            $entry = LogEntry::forError(
                requestId: 'req-123',
                exception: $exception,
                includeTrace: true,
            );

            expect($entry->data)->toHaveKey('trace');
            expect($entry->data['trace'])->toBeString();
        });
    });

    describe('Type Constants', function (): void {
        it('has correct type constants', function (): void {
            expect(LogEntry::TYPE_REQUEST)->toBe('request');
            expect(LogEntry::TYPE_RESPONSE)->toBe('response');
            expect(LogEntry::TYPE_SESSION)->toBe('session');
            expect(LogEntry::TYPE_PAYMENT)->toBe('payment');
            expect(LogEntry::TYPE_ERROR)->toBe('error');
        });
    });

    describe('Serialization', function (): void {
        it('converts to array for storage', function (): void {
            $entry = LogEntry::forRequest(
                requestId: 'req-123',
                endpoint: '/checkout-sessions',
                method: 'POST',
                agent: 'Claude/1.0',
                ipAddress: '10.0.0.1',
            );

            $array = $entry->toArray();

            expect($array)->toHaveKey('request_id');
            expect($array)->toHaveKey('type');
            expect($array)->toHaveKey('endpoint');
            expect($array)->toHaveKey('method');
            expect($array)->toHaveKey('agent');
            expect($array)->toHaveKey('ip_address');
            expect($array)->toHaveKey('created_at');
            expect($array['request_id'])->toBe('req-123');
            expect($array['type'])->toBe('request');
        });

        it('creates from database row', function (): void {
            $row = [
                'id' => 42,
                'request_id' => 'req-789',
                'type' => 'response',
                'endpoint' => '/checkout-sessions/abc',
                'method' => 'GET',
                'session_id' => 'sess-abc',
                'agent' => 'Gemini/1.0',
                'ip_address' => '172.16.0.1',
                'status_code' => 200,
                'duration_ms' => 45,
                'data' => json_encode(['foo' => 'bar']),
                'created_at' => '2026-01-18 12:00:00',
            ];

            $entry = LogEntry::fromRow($row);

            expect($entry->id)->toBe(42);
            expect($entry->requestId)->toBe('req-789');
            expect($entry->type)->toBe('response');
            expect($entry->endpoint)->toBe('/checkout-sessions/abc');
            expect($entry->method)->toBe('GET');
            expect($entry->sessionId)->toBe('sess-abc');
            expect($entry->agent)->toBe('Gemini/1.0');
            expect($entry->ipAddress)->toBe('172.16.0.1');
            expect($entry->statusCode)->toBe(200);
            expect($entry->durationMs)->toBe(45);
            expect($entry->data)->toBe(['foo' => 'bar']);
            expect($entry->createdAt)->toBeInstanceOf(DateTimeImmutable::class);
            expect($entry->createdAt->format('Y-m-d H:i:s'))->toBe('2026-01-18 12:00:00');
        });

        it('handles null values in database row', function (): void {
            $row = [
                'request_id' => 'req-minimal',
                'type' => 'request',
                'endpoint' => null,
                'method' => null,
                'session_id' => null,
                'agent' => null,
                'ip_address' => null,
                'status_code' => null,
                'duration_ms' => null,
                'data' => null,
                'created_at' => null,
            ];

            $entry = LogEntry::fromRow($row);

            expect($entry->requestId)->toBe('req-minimal');
            expect($entry->endpoint)->toBeNull();
            expect($entry->statusCode)->toBeNull();
            expect($entry->data)->toBe([]);
        });

        it('handles malformed JSON in data field', function (): void {
            $row = [
                'request_id' => 'req-bad-json',
                'type' => 'request',
                'data' => 'not valid json{{{',
            ];

            $entry = LogEntry::fromRow($row);

            expect($entry->data)->toBe([]);
        });
    });
});
