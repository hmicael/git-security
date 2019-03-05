<?php
// /src/AppBundle/Controller/RestLoginController.php

namespace App\Controller;

use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\Controller\FOSRestController;
use FOS\RestBundle\Routing\ClassResourceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class RestLoginController extends FOSRestController implements ClassResourceInterface
{
    /**
     * Login
     * @Rest\Post(
     *     path="/login",
     *     name="api_login"
     * )
     */
    public function postAction()
    {
        // route handled by Lexik JWT Authentication Bundle
        throw new \DomainException('You should never see this');
    }

    /**
     * This is used for auth_subrequest in apigateway to check if an user is
     * authentificated
     * @Rest\Get(
     *     path="/auth",
     *     name="api_auth"
     * )
     */
    public function auth()
    {
        // this is used for auth_subrequest in apigateway to check if an user is authentificated
        // if not, it will send an exception with 401 error code
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $response = new JsonResponse(
            ['message' => 'You\'re authentificated !'],
            JsonResponse::HTTP_OK
        );
        return $response;
    }
}
