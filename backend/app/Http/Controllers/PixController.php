<?php

namespace App\Http\Controllers;

use App\Helpers\PixPayload;
use App\Models\User;
use Endroid\QrCode\Builder\Builder;
use Illuminate\Http\Request;

class PixController extends Controller
{
    /**
     * Gera um QR Code e Copia e Cola Pix com base na chave do usuário
     */
    public function gerarPix(Request $request)
    {
        $authUser = auth()->user();
        $user = User::where('email', $request->email)->firstOrFail();

        if ($user->id !== $authUser->id && ! $this->compartilhaGrupo($authUser, $user)) {
            return response()->json(['message' => 'Você não tem permissão para gerar o Pix deste usuário.'], 403);
        }

        if (! $user->pix) {
            return response()->json(['message' => 'Usuário não tem chave Pix.'], 400);
        }

        $pix = (new PixPayload)
            ->setPixKey($user->pix)
            ->setDescription('Pagamento via Pix')
            ->setMerchantName('Novemax')
            ->setMerchantCity('SAO PAULO')
            ->setAmount($request->valor)
            ->setTxid('NOVEMAX'.strtoupper(substr(md5(uniqid()), 0, 6)));

        $code = $pix->getPayload(); // Copia e cola

        $qr = Builder::create()
            ->data($code)
            ->size(300)
            ->margin(10)
            ->build();

        return response()->json([
            'qrcode' => 'data:image/png;base64,'.base64_encode($qr->getString()),
            'copiacola' => $code,
        ]);
    }

    /**
     * Só é possível gerar o Pix de outro usuário se ambos forem membros do mesmo grupo
     * (ou seja, existe uma despesa compartilhada que justifica a cobrança).
     */
    private function compartilhaGrupo(User $authUser, User $user): bool
    {
        return $authUser->groups()
            ->whereHas('members', fn ($q) => $q->where('user_id', $user->id))
            ->exists();
    }
}
