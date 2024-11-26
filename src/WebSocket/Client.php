<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Hyperf\Engine\WebSocket;

use Hyperf\WebSocketClient\ClientInterface;
use Hyperf\WebSocketClient\Client as BaseClient;
use Hyperf\WebSocketClient\Frame;
use Swow\Psr7\Client\Client as SwowClient;
use Swow\Psr7\Message\WebSocketFrame;
use Hyperf\WebSocketClient\Constant\Opcode;
use Hyperf\HttpMessage\Uri\Uri;
use Swow\Psr7\Message\Request;

class Client extends BaseClient implements ClientInterface
{
    protected SwowClient $client;

    /**
     * 存储待发送的headers
     */
    protected array $pendingHeaders = [];

    public function __construct(protected Uri $uri, array $headers = [])
    {
        $host = $uri->getHost();
        $port = $uri->getPort();
        $ssl = $uri->getScheme() === 'wss';

        var_dump($host, $port, $ssl);
        $this->client = new SwowClient();
        // 先建立连接
        $this->client->connect($host, $port ?: ($ssl ? 443 : 80));
        
        // 如果是 WSS,启用 SSL
        if ($ssl) {
            $this->client->enableCrypto();
        }
        
        $this->setHeaders($headers);
        parent::__construct($uri, $headers);
    }

    public function setHeaders(array $headers): static
    {
        $this->headers = $headers;
        $this->pendingHeaders = $headers;
        return $this;
    }

    protected function connectInternal(string $path): bool
    {
        try {
            // 创建请求对象并设置headers
            $request = new Request('GET', $path);
            foreach ($this->pendingHeaders as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
            
            // 升级到WebSocket
            $this->client->upgradeToWebSocket($request);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function recvInternal(float $timeout = -1): Frame
    {
        try {
            $data = $this->client->recvWebSocketFrame($timeout);
            if ($data instanceof WebSocketFrame) {
                return new Frame(
                    $data->getFin(), 
                    $data->getOpcode(), 
                    (string) $data->getPayloadData()
                );
            }
        } catch (\Throwable $e) {
        }
        return new Frame(false, 0, '');
    }

    public function push(string $data, int $opcode = Opcode::TEXT, ?int $flags = null): bool
    {
        try {
            $frame = new WebSocketFrame();
            $frame->setOpcode($opcode);
            $frame->setPayloadData($data);
            $this->client->sendWebSocketFrame($frame);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function close(): bool
    {
        try {
            return $this->client->close();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getErrCode(): int
    {
        return 0;
    }

    public function getErrMsg(): string
    {
        return '';
    }
} 