<?php

declare(strict_types=1);

use PhpOpcua\Client\Exception\EncodingException;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Wire\CoreWireTypes;
use PhpOpcua\Client\Wire\WireTypeRegistry;
use PhpOpcua\SessionManager\Exception\DaemonException;
use PhpOpcua\SessionManager\Ipc\WireMessageCodec;

function buildCodec(int $maxBytes = 16 * 1024 * 1024, int $maxDepth = 32): WireMessageCodec
{
    $registry = new WireTypeRegistry();
    CoreWireTypes::register($registry);

    return new WireMessageCodec($registry, $maxBytes, $maxDepth);
}

describe('WireMessageCodec: request encoding', function () {

    it('encodes a request with typed arguments', function () {
        $codec = buildCodec();
        $frame = $codec->encodeRequest(42, 'read', [NodeId::numeric(0, 85), 13, false]);
        $decoded = json_decode($frame, true);
        expect($decoded['id'])->toBe(42);
        expect($decoded['t'])->toBe('req');
        expect($decoded['method'])->toBe('read');
        expect($decoded['args'][0])->toBe(['__t' => 'NodeId', 'v' => 'i=85']);
        expect($decoded['args'][1])->toBe(13);
        expect($decoded['args'][2])->toBeFalse();
    });

    it('rejects arguments that the registry cannot encode', function () {
        $codec = buildCodec();
        expect(fn () => $codec->encodeRequest(1, 'custom', [new stdClass()]))
            ->toThrow(EncodingException::class);
    });

    it('rejects an envelope that exceeds maxFrameBytes', function () {
        $codec = buildCodec(maxBytes: 64);
        $big = str_repeat('x', 1000);
        expect(fn () => $codec->encodeRequest(1, 'echo', [$big]))
            ->toThrow(DaemonException::class, 'exceeds');
    });
});

describe('WireMessageCodec: response encoding', function () {

    it('encodes a success response with a typed payload', function () {
        $codec = buildCodec();
        $frame = $codec->encodeOkResponse(7, NodeId::string(2, 'Counter'));
        $decoded = json_decode($frame, true);
        expect($decoded)->toBe([
            'id' => 7,
            't' => 'res',
            'ok' => true,
            'data' => ['__t' => 'NodeId', 'v' => 'ns=2;s=Counter'],
        ]);
    });

    it('encodes a success response with null data', function () {
        $codec = buildCodec();
        $frame = $codec->encodeOkResponse(8, null);
        $decoded = json_decode($frame, true);
        expect($decoded['data'])->toBeNull();
    });

    it('encodes an error response with only class + message (no stack trace)', function () {
        $codec = buildCodec();
        $frame = $codec->encodeErrorResponse(9, 'ServiceException', 'BadNodeIdUnknown');
        $decoded = json_decode($frame, true);
        expect($decoded)->toBe([
            'id' => 9,
            't' => 'res',
            'ok' => false,
            'error' => ['class' => 'ServiceException', 'message' => 'BadNodeIdUnknown'],
        ]);
    });
});

describe('WireMessageCodec: frame decoding', function () {

    it('round-trips a request with mixed args', function () {
        $codec = buildCodec();
        $frame = $codec->encodeRequest(1, 'browse', [NodeId::numeric(0, 85), 'Organizes']);
        $decoded = $codec->decodeFrame($frame);
        expect($decoded['id'])->toBe(1);
        expect($decoded['t'])->toBe('req');
        expect($decoded['method'])->toBe('browse');
        expect($decoded['args'][0])->toBeInstanceOf(NodeId::class);
        expect((string) $decoded['args'][0])->toBe('i=85');
        expect($decoded['args'][1])->toBe('Organizes');
    });

    it('round-trips a success response', function () {
        $codec = buildCodec();
        $frame = $codec->encodeOkResponse(2, NodeId::guid(0, '72962b91-fa75-4ae6-8d28-b404dc7daf63'));
        $decoded = $codec->decodeFrame($frame);
        expect($decoded['ok'])->toBeTrue();
        expect($decoded['data'])->toBeInstanceOf(NodeId::class);
        expect((string) $decoded['data'])->toBe('g=72962b91-fa75-4ae6-8d28-b404dc7daf63');
    });

    it('round-trips an error response', function () {
        $codec = buildCodec();
        $frame = $codec->encodeErrorResponse(3, 'ConnectionException', 'Not connected');
        $decoded = $codec->decodeFrame($frame);
        expect($decoded['ok'])->toBeFalse();
        expect($decoded['error']['class'])->toBe('ConnectionException');
        expect($decoded['error']['message'])->toBe('Not connected');
    });

    it('rejects a frame larger than maxFrameBytes', function () {
        $codec = buildCodec(maxBytes: 20);
        expect(fn () => $codec->decodeFrame(str_repeat('x', 100)))
            ->toThrow(DaemonException::class, 'byte cap');
    });

    it('rejects malformed JSON', function () {
        $codec = buildCodec();
        expect(fn () => $codec->decodeFrame('{not json'))
            ->toThrow(DaemonException::class, 'Malformed IPC frame');
    });

    it('rejects top-level non-object JSON', function () {
        $codec = buildCodec();
        expect(fn () => $codec->decodeFrame('[1,2,3]'))
            ->toThrow(DaemonException::class, 'top-level JSON value must be an object');
    });

    it('rejects a frame missing the id field', function () {
        $codec = buildCodec();
        expect(fn () => $codec->decodeFrame('{"t":"req","method":"x","args":[]}'))
            ->toThrow(DaemonException::class, 'missing or non-integer "id"');
    });

    it('rejects an unknown t value', function () {
        $codec = buildCodec();
        expect(fn () => $codec->decodeFrame('{"id":1,"t":"other"}'))
            ->toThrow(DaemonException::class, '"t" must be');
    });

    it('rejects a request without method', function () {
        $codec = buildCodec();
        expect(fn () => $codec->decodeFrame('{"id":1,"t":"req","args":[]}'))
            ->toThrow(DaemonException::class, 'missing or non-string "method"');
    });

    it('rejects a request without args array', function () {
        $codec = buildCodec();
        expect(fn () => $codec->decodeFrame('{"id":1,"t":"req","method":"x"}'))
            ->toThrow(DaemonException::class, 'missing or non-array "args"');
    });

    it('rejects a response without ok', function () {
        $codec = buildCodec();
        expect(fn () => $codec->decodeFrame('{"id":1,"t":"res"}'))
            ->toThrow(DaemonException::class, 'missing or non-boolean "ok"');
    });

    it('rejects an error response with a malformed error object', function () {
        $codec = buildCodec();
        expect(fn () => $codec->decodeFrame('{"id":1,"t":"res","ok":false,"error":{"class":"X"}}'))
            ->toThrow(DaemonException::class, 'error" must be');
    });

    it('rejects a frame whose JSON is too deeply nested', function () {
        $codec = buildCodec(maxDepth: 4);
        $deep = '{"id":1,"t":"res","ok":true,"data":' . str_repeat('{"x":', 10) . '1' . str_repeat('}', 10) . '}';
        expect(fn () => $codec->decodeFrame($deep))
            ->toThrow(DaemonException::class, 'Malformed IPC frame');
    });
});

describe('WireMessageCodec: security posture', function () {

    it('a frame carrying an unregistered __t is rejected at decode time', function () {
        $codec = buildCodec();
        $frame = '{"id":1,"t":"res","ok":true,"data":{"__t":"NotRegistered","x":1}}';
        expect(fn () => $codec->decodeFrame($frame))
            ->toThrow(EncodingException::class, 'Unknown wire type id');
    });

    it('registry() returns the shared WireTypeRegistry for late registrations', function () {
        $codec = buildCodec();
        expect($codec->registry())->toBeInstanceOf(WireTypeRegistry::class);
    });
});
