<?php

namespace App\Controller\Concerns;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Helper de sesión compartido por los controladores (
 * antes → problema: sessionUser/userHasAction duplicados en AdminController,
 * ResultsController y ApiBaseController — tres copias de la misma lógica).
 * Cambio: un único trait con la lectura de sesión y el chequeo de acciones.
 */
trait SessionUserTrait
{
    /**
     * Usuario logueado en la sesión (array del contrato legacy) o null.
     */
    protected function sessionUser(Request $request): ?array
    {
        $user = $request->getSession()->get('user');
        return is_array($user) ? $user : null;
    }

    /**
     * ¿El usuario tiene la acción del módulo? (los admins tienen todas)
     */
    protected function userHasAction(?array $user, string $action): bool
    {
        if (!$user) {
            return false;
        }
        if (($user['type'] ?? '') === 'admin') {
            return true;
        }
        return in_array($action, (array) ($user['actions'] ?? []), true);
    }

    /**
     * Respuesta 403 estándar para acciones sin permiso.
     */
    protected function forbiddenAccess(): JsonResponse
    {
        return $this->json(['message' => 'No tienes permisos para esta acción'], 403);
    }
}
