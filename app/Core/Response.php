<?php
declare(strict_types=1);

namespace School\Core;

final class Response
{
    public function __construct(private string $body = '', private int $status = 200, private array $headers = []) {}
    public function send(): never
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) header($name . ': ' . $value);
        echo $this->body;
        exit;
    }
    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }
    public static function json(array $data, int $status = 200): self
    {
        return new self(json_encode($data, JSON_THROW_ON_ERROR), $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }
}
