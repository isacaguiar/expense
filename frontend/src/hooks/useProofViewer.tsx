import React, { useCallback, useState } from 'react';
import ProofViewerDialog from '../components/ProofViewerDialog';

type ProofLinkProps = {
  href: string;
  onClick: (event: React.MouseEvent<HTMLElement>) => void;
  'aria-haspopup': 'dialog';
};

type UseProofViewer = {
  // Props para espalhar no gatilho (`<a>`, `Chip component="a"`, `IconButton component="a"`).
  proofLinkProps: (url: string) => ProofLinkProps;
  // O diálogo, para renderizar UMA vez por tela ou lista.
  proofViewer: React.ReactNode;
};

/**
 * Liga um link de comprovante ao `ProofViewerDialog` (feature 20261120-ver-comprovante-em-modal, plan §2).
 * O gatilho mantém o `href`: papel `link`, botão do meio e `ctrl`/`cmd`/`shift`/`alt` + clique seguem
 * abrindo o arquivo em outra aba; só o clique simples (botão principal, sem modificador) abre o modal.
 */
const useProofViewer = (): UseProofViewer => {
  const [state, setState] = useState<{ open: boolean; url: string | null }>({ open: false, url: null });

  const proofLinkProps = useCallback(
    (url: string): ProofLinkProps => ({
      href: url,
      'aria-haspopup': 'dialog',
      onClick: event => {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        setState({ open: true, url });
      }
    }),
    []
  );

  const close = useCallback(() => setState(current => ({ ...current, open: false })), []);

  // A URL fica no estado depois de fechar para a imagem não sumir durante a animação de saída.
  const proofViewer = <ProofViewerDialog open={state.open} url={state.url} onClose={close} />;

  return { proofLinkProps, proofViewer };
};

export default useProofViewer;
