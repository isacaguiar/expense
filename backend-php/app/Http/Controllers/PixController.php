<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Helpers\PixPayload;
use Endroid\QrCode\Builder\Builder;

class PixController extends Controller
{
    /**
     * Gera um QR Code e Copia e Cola Pix com base na chave do usuário
     */
    public function gerarPix(Request $request)
    {
        $user = User::where('email', $request->email)->firstOrFail();

        if (!$user->pix) {
            return response()->json(['message' => 'Usuário não tem chave Pix.'], 400);
        }

        $pix = (new PixPayload)
            ->setPixKey($user->pix)
            ->setDescription('Pagamento via Pix')
            ->setMerchantName('Novemax')
            ->setMerchantCity('SAO PAULO')
            ->setAmount($request->valor)
            ->setTxid('NOVEMAX' . strtoupper(substr(md5(uniqid()), 0, 6)));

        $code = $pix->getPayload(); // Copia e cola

        $qr = Builder::create()
            ->data($code)
            ->size(300)
            ->margin(10)
            ->build();

        return response()->json([
            'qrcode' => 'data:image/png;base64,' . base64_encode($qr->getString()),
            'copiacola' => $code,
        ]);
    }
}