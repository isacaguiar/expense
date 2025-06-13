<?php

namespace App\Helpers;

class PixPayload
{
    private string $pixKey;
    private ?string $description = null;
    private string $merchantName = 'NOVEMAX';
    private string $merchantCity = 'SAO PAULO';
    private ?string $amount = null;
    private string $txid = 'NOVEMAX123';

    public function setPixKey(string $key): self
    {
        $this->pixKey = $key;
        return $this;
    }

    public function setDescription(?string $desc): self
    {
        $this->description = $desc;
        return $this;
    }

    public function setMerchantName(string $name): self
    {
        $this->merchantName = strtoupper($name);
        return $this;
    }

    public function setMerchantCity(string $city): self
    {
        $this->merchantCity = strtoupper($city);
        return $this;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = number_format($amount, 2, '.', '');
        return $this;
    }

    public function setTxid(string $txid): self
    {
        $this->txid = $txid;
        return $this;
    }

    private function formatField(string $id, string $value): string
    {
        $len = str_pad(strlen($value), 2, '0', STR_PAD_LEFT);
        return $id . $len . $value;
    }

    public function getPayload(): string
    {
        $gui = $this->formatField('00', 'br.gov.bcb.pix');
        $key = $this->formatField('01', $this->pixKey);
        $desc = $this->description ? $this->formatField('02', $this->description) : '';
        $merchantAccountInfo = $this->formatField('26', $gui . $key . $desc);

        $payload = [
            $this->formatField('00', '01'),                  // Payload Format Indicator
            $this->formatField('01', '12'),                  // Point of Initiation Method (static)
            $merchantAccountInfo,
            $this->formatField('52', '0000'),                // Merchant category code
            $this->formatField('53', '986'),                 // Currency (BRL)
            $this->amount ? $this->formatField('54', $this->amount) : '',
            $this->formatField('58', 'BR'),                  // Country
            $this->formatField('59', $this->merchantName),   // Merchant name
            $this->formatField('60', $this->merchantCity),   // Merchant city
            $this->formatField('62', $this->formatField('05', $this->txid)), // TXID
        ];

        $fullPayload = implode('', $payload);
        $crc = $this->formatField('63', strtoupper($this->crc16($fullPayload . '6304')));
        return $fullPayload . $crc;
    }

    private function crc16(string $payload): string
    {
        $polynomial = 0x1021;
        $result = 0xFFFF;

        for ($i = 0; $i < strlen($payload); $i++) {
            $result ^= (ord($payload[$i]) << 8);
            for ($j = 0; $j < 8; $j++) {
                if (($result << 1) & 0x10000) {
                    $result = (($result << 1) ^ $polynomial) & 0xFFFF;
                } else {
                    $result = ($result << 1) & 0xFFFF;
                }
            }
        }

        return strtoupper(dechex($result));
    }
}
