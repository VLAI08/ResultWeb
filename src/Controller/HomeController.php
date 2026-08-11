<?php

namespace App\Controller;

use App\Service\DomainsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;

class HomeController extends AbstractController
{
    public function __construct(private DomainsService $domains, private SessionInterface $session)
    {
    }

    /**
     * @Route("/", name="root")
     */
    public function index(Request $request): Response
    {
        // Simula la lógica legacy: requiere sesión 'user'
        $user = $this->session->get('user');
        if (!$user) {
            return $this->render('security/login.html.twig');
        }
        $identificationTypes = $this->domains->listDomainsActive('identificationtype');
        // Enrutar según tipo de usuario: admin va a su panel del sidebar; person/company usan la UI completa
        $type = (string) ($user['type'] ?? 'person');
        if ($type === 'admin') {
            return $this->redirectToRoute('admin_patients');
        }
        return $this->render('home/full.html.twig', [
            'title' => 'Inicio',
            'identification_types' => $identificationTypes,
            'user' => $user,
        ]);
    }

    /**
     * @Route("/admin", name="admin")
     */
    public function admin(): Response
    {
        // Compatibilidad: usamos 'user' en sesión y validamos que sea admin
        $user = $this->session->get('user');
        if (!$user || ($user['type'] ?? '') !== 'admin') {
            return $this->render('security/login.html.twig');
        }
        return $this->redirectToRoute('admin_patients');
    }
}
