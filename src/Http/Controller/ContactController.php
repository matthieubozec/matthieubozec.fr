<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Contact\ContactSubmitter;
use App\Contact\Model\ContactMessage;
use App\Http\Form\ContactMessageType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Turbo\TurboBundle;

class ContactController extends AbstractController
{
    #[Route(name: 'contact', path: '/contact', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        ContactSubmitter $submitter,
        #[MapQueryParameter] ?string $status = null,
    ): Response {
        $message = new ContactMessage();
        $form = $this->createForm(ContactMessageType::class, $message);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $submitter->submit($message);

            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                return $this->renderBlock('contact/index.html.twig', 'success_stream');
            }

            return $this->redirectToRoute('contact', ['status' => 'success']);
        }

        if ($form->isSubmitted() && !$form->isValid()) {
            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                return $this->renderBlock('contact/index.html.twig', 'error_stream', [
                    'form' => $form,
                ]);
            }

            $status = 'error';
        }

        return $this->render('contact/index.html.twig', [
            'form' => $form,
            'status' => $status,
        ]);
    }
}
