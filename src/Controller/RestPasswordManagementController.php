<?php

// /src/App/Controller/RestPasswordManagementController.php

namespace App\Controller;

use App\Entity\User;
use FOS\RestBundle\Controller\Annotations;
use FOS\RestBundle\Controller\Annotations\RouteResource;
use FOS\RestBundle\Controller\FOSRestController;
use FOS\RestBundle\Routing\ClassResourceInterface;
use FOS\UserBundle\Event\FilterUserResponseEvent;
use FOS\UserBundle\Event\FormEvent;
use FOS\UserBundle\Event\GetResponseNullableUserEvent;
use FOS\UserBundle\Event\GetResponseUserEvent;
use FOS\UserBundle\Form\Type\ChangePasswordFormType;
use FOS\UserBundle\Form\Type\ResettingFormType;
use FOS\UserBundle\FOSUserEvents;
use Nelmio\ApiDocBundle\Annotation\Model;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Swagger\Annotations as SWG;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @Annotations\Prefix("password")
 * @RouteResource("password", pluralize=false)
 */
class RestPasswordManagementController extends FOSRestController implements ClassResourceInterface
{
    /**
     * Change user password
     *
     * @ParamConverter("user", options={"mapping": {"user": "userId"}})
     *
     * @Annotations\Post(
     *     path="/password/{user}/edit",
     *     name="api_change_password",
     *     requirements={"user"="\d+"}
     * )
     * @SWG\Response(
     *     response=204,
     *     description="The user's password corresponding to the id is edited"
     * )
     * @SWG\Parameter(
     *     name="user",
     *     in="path",
     *     type="integer",
     *     description="The id of the user"
     * )
     * @SWG\Parameter(
     *     name="user",
     *     in="body",
     *     @Model(type=ChangePasswordFormType::class),
     *     description="The new password to register"
     * )
     * @SWG\Tag(name="Password")
     * @param Request $request
     * @param User $user
     * @return null|\Symfony\Component\Form\FormInterface|JsonResponse|Response
     */
    public function edit(Request $request, User $user)
    {
        // to make sure that the user or admin is editing his/one password
        if ($user !== $this->getUser() &&
            !$this->get('security.authorization_checker')->isGranted('ROLE_ADMIN')
        ) {
            throw new AccessDeniedHttpException();
        }
        //return $this->getUser()->getEmail();
        /** @var $dispatcher \Symfony\Component\EventDispatcher\EventDispatcherInterface */
        $dispatcher = $this->get('event_dispatcher');
        /** @var $formFactory \FOS\UserBundle\Form\Factory\FactoryInterface */
        $formFactory = $this->get('fos_user.change_password.form.factory');
        /** @var $userManager \FOS\UserBundle\Model\UserManagerInterface */
        $userManager = $this->get('fos_user.user_manager');
        $event = new GetResponseUserEvent($user, $request);
        $dispatcher->dispatch(FOSUserEvents::CHANGE_PASSWORD_INITIALIZE, $event);

        if (null !== $event->getResponse()) {
            return $event->getResponse();
        }
        // we don't need CSRF here
        $form = $formFactory->createForm([
            'csrf_protection' => false
        ]);
        $form->setData($user);
        $form->submit($request->request->all());

        if (!$form->isValid()) {
            return $form; //here we return the error converted by FOSREST
        }
        $event = new FormEvent($form, $request);
        $dispatcher->dispatch(FOSUserEvents::CHANGE_PASSWORD_SUCCESS, $event);
        $userManager->updateUser($user);
        // we'll use fosuser flash message for the JSON message for the following response
        if (null === $response = $event->getResponse()) {
            return new JsonResponse(
                $this->get('translator')->trans('change_password.flash.success', [], 'FOSUserBundle'),
                Response::HTTP_NO_CONTENT
            );
        }
        $dispatcher->dispatch(FOSUserEvents::CHANGE_PASSWORD_COMPLETED, new FilterUserResponseEvent($user, $request, $response));
        return new JsonResponse(
            $this->get('translator')->trans('change_password.flash.success', [], 'FOSUserBundle'),
            Response::HTTP_NO_CONTENT
        );
    }

    /**
     * Send a request to reset the password
     * @Annotations\Post(
     *    path="/password/reset/request",
     *    name="api_request_password_reset"
     * )
     * @SWG\Response(
     *     response=200,
     *     description="Token for confirmation"
     * )
     * @SWG\Parameter(
     *     name="request",
     *     in="body",
     *     @Model(type=User::class),
     *     description="The username of the user who request to reset his password"
     * )
     * @SWG\Tag(name="Password")
     * @param Request $request
     * @return null|JsonResponse|Response
     */
    public function requestResetAction(Request $request)
    {
        $username = $request->request->get('username');
        /** @var $user UserInterface */
        $user = $this->get('fos_user.user_manager')->findUserByUsernameOrEmail($username);
        /** @var $dispatcher EventDispatcherInterface */
        $dispatcher = $this->get('event_dispatcher');
        /* Dispatch init event */
        $event = new GetResponseNullableUserEvent($user, $request);
        $dispatcher->dispatch(FOSUserEvents::RESETTING_SEND_EMAIL_INITIALIZE, $event);
        if (null !== $event->getResponse()) {
            return $event->getResponse();
        }
        // adaptation to JSON response
        if (null === $user) {
            return new JsonResponse(
                'User not recognised',
                JsonResponse::HTTP_FORBIDDEN
            );
        }
        $event = new GetResponseUserEvent($user, $request);
        $dispatcher->dispatch(FOSUserEvents::RESETTING_RESET_REQUEST, $event);
        if (null !== $event->getResponse()) {
            return $event->getResponse();
        }
        if ($user->isPasswordRequestNonExpired($this->container->getParameter('fos_user.resetting.token_ttl'))) {
            // adaptation to JSON response
            return new JsonResponse(
                $this->get('translator')->trans('resetting.password_already_requested', [], 'FOSUserBundle'),
                JsonResponse::HTTP_FORBIDDEN
            );
        }
        if (null === $user->getConfirmationToken()) {
            /** @var $tokenGenerator \FOS\UserBundle\Util\TokenGeneratorInterface */
            $tokenGenerator = $this->get('fos_user.util.token_generator');
            $user->setConfirmationToken($tokenGenerator->generateToken());
        }
        /* Dispatch confirm event */
        $event = new GetResponseUserEvent($user, $request);
        $dispatcher->dispatch(FOSUserEvents::RESETTING_SEND_EMAIL_CONFIRM, $event);
        if (null !== $event->getResponse()) {
            return $event->getResponse();
        }
        // email won't be send because of dependance to wordpress
        // $this->get('fos_user.mailer')->sendResettingEmailMessage($user);
        $user->setPasswordRequestedAt(new \DateTime());
        $this->get('fos_user.user_manager')->updateUser($user);
        /* Dispatch completed event */
        $event = new GetResponseUserEvent($user, $request);
        $dispatcher->dispatch(FOSUserEvents::RESETTING_SEND_EMAIL_COMPLETED, $event);

        if (null !== $event->getResponse()) {
            return $event->getResponse();
        }
        // normaly, response should be like this
        /*return new JsonResponse(
            $this->get('translator')->trans(
                'resetting.check_email',
                ['%tokenLifetime%' => floor($this->container->getParameter('fos_user.resetting.token_ttl') / 3600)],
                'FOSUserBundle'
            ),
            JsonResponse::HTTP_OK
        );*/
        // but as we reset password from wordpress, only token'll be send without any email
        return new JsonResponse(
            ['token' => $user->getConfirmationToken()],
            JsonResponse::HTTP_OK
        );
    }

    /**
     * Reset user password
     * @Annotations\Post(
     *     path="/password/reset/confirm",
     *     name="api_confirm_password_reset"
     * )
     * @SWG\Response(
     *     response=200,
     *     description="Password is successfully reset"
     * )
     * @SWG\Parameter(
     *     name="request",
     *     in="body",
     *     @Model(type=ResettingFormType::class),
     *     description="The new password with some token"
     * )
     * @SWG\Tag(name="Password")
     * @param Request $request
     * @return null|\Symfony\Component\Form\FormInterface|JsonResponse|Response
     */
    public function confirmResetAction(Request $request)
    {
        $token = $request->query->get('token');
        if (null === $token) {
            return new JsonResponse('You must submit a token.', JsonResponse::HTTP_BAD_REQUEST);
        }

        /** @var $formFactory \FOS\UserBundle\Form\Factory\FactoryInterface */
        $formFactory = $this->get('fos_user.resetting.form.factory');
        /** @var $userManager \FOS\UserBundle\Model\UserManagerInterface */
        $userManager = $this->get('fos_user.user_manager');
        /** @var $dispatcher \Symfony\Component\EventDispatcher\EventDispatcherInterface */
        $dispatcher = $this->get('event_dispatcher');

        $user = $userManager->findUserByConfirmationToken($token);

        if (null === $user) {
            // no translation provided for this in \FOS\UserBundle\Controller\ResettingController
            return new JsonResponse(
                sprintf('The user with "confirmation token" does not exist for value "%s"', $token),
                JsonResponse::HTTP_BAD_REQUEST
            );
        }

        $event = new GetResponseUserEvent($user, $request);
        $dispatcher->dispatch(FOSUserEvents::RESETTING_RESET_INITIALIZE, $event);

        if (null !== $event->getResponse()) {
            return $event->getResponse();
        }

        $form = $formFactory->createForm([
            'csrf_protection' => false,
            'allow_extra_fields' => true //here we allow extra-field because we need the token field
        ]);
        $form->setData($user);
        $form->submit($request->request->all());

        if (!$form->isValid()) {
            return $form;
        }

        $event = new FormEvent($form, $request);
        $dispatcher->dispatch(FOSUserEvents::RESETTING_RESET_SUCCESS, $event);

        $userManager->updateUser($user);

        if (null === $response = $event->getResponse()) {
            return new JsonResponse(
                $this->get('translator')->trans('resetting.flash.success', [], 'FOSUserBundle'),
                JsonResponse::HTTP_OK
            );
        }

        $dispatcher->dispatch(FOSUserEvents::RESETTING_RESET_COMPLETED, new FilterUserResponseEvent($user, $request, $response));

        return new JsonResponse(
            $this->get('translator')->trans('resetting.flash.success', [], 'FOSUserBundle'),
            JsonResponse::HTTP_OK
        );
    }
}
