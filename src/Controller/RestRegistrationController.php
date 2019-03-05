<?php

namespace App\Controller;

use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\Controller\FOSRestController;
use FOS\RestBundle\Routing\ClassResourceInterface;
use FOS\UserBundle\Event\FilterUserResponseEvent;
use FOS\UserBundle\Event\FormEvent;
use FOS\UserBundle\Event\GetResponseUserEvent;
use FOS\UserBundle\Form\Type\RegistrationFormType;
use FOS\UserBundle\FOSUserEvents;
use Nelmio\ApiDocBundle\Annotation\Model;
use Swagger\Annotations as SWG;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class RestRegistrationController extends FOSRestController implements ClassResourceInterface
{
    /**
     * This is the content of FOSUB RegistrationController, all we did is to adapt it
     * To return JSON response.
     * @Rest\Post(
     *    path="/register",
     *    name="api_registration"
     * )
     * @Rest\View(StatusCode=200)
     * @SWG\Response(
     *     response=201,
     *     description="User is registered"
     * )
     * @SWG\Parameter(
     *     name="user",
     *     in="body",
     *     @Model(type=RegistrationFormType::class),
     *     description="The user to register"
     * )
     * @SWG\Tag(name="Registration")
     * @param Request $request
     * @return null|\Symfony\Component\Form\FormInterface|JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function registerAction(Request $request)
    {
        /** @var $formFactory \FOS\UserBundle\Form\Factory\FactoryInterface */
        $formFactory = $this->get('fos_user.registration.form.factory');
        /** @var $userManager \FOS\UserBundle\Model\UserManagerInterface */
        $userManager = $this->get('fos_user.user_manager');
        /** @var $eventDispatcher \Symfony\Component\EventDispatcher\EventDispatcherInterface */
        $eventDispatcher = $this->get('event_dispatcher');

        $user = $userManager->createUser();
        $user->setEnabled(true);

        $event = new GetResponseUserEvent($user, $request);
        $eventDispatcher->dispatch(FOSUserEvents::REGISTRATION_INITIALIZE, $event);

        if (null !== $event->getResponse()) {
            return $event->getResponse();
        }

        $form = $formFactory->createForm([
            'csrf_protection' => false,
            'allow_extra_fields' => true //here we allow extra-field because we need the user_id field
        ]);

        $form->setData($user);
        $form->submit($request->request->all());
        $user->setUserId($request->request->get('user_id'));
        $user->addRole($request->request->get('role'));

        if (!$form->isValid()) {
            $event = new FormEvent($form, $request);
            $eventDispatcher->dispatch(FOSUserEvents::REGISTRATION_FAILURE, $event);

            if (null !== $response = $event->getResponse()) {
                return $response; //here we return the error converted by FOSREST
            }

            return $form; //here we return the error converted by FOSREST
        }

        $event = new FormEvent($form, $request);
        $eventDispatcher->dispatch(FOSUserEvents::REGISTRATION_SUCCESS, $event);

        // no event create a new response so we'll pass the response of the current event
        if ($event->getResponse()) {
            return $event->getResponse();
        }

        $userManager->updateUser($user);

        // here we make a JsonResponse instead of original FOSUser Response
        // and we'll use fosuser flash message for the JSON message for the following response
        $response = new JsonResponse(
            [
                'message' => $this->get('translator')->trans('registration.flash.user_created', [], 'FOSUserBundle'), // here we use fosuser flashmessage
                'token' => $this->get('lexik_jwt_authentication.jwt_manager')->create($user), // creates JWT
            ],
            JsonResponse::HTTP_CREATED,
            [
                'Location' => $this->generateUrl(
                    'api_profile',
                    ['user' => $user->getId()],
                    UrlGeneratorInterface::ABSOLUTE_URL
                )
            ]
        );

        $eventDispatcher->dispatch(
            FOSUserEvents::REGISTRATION_COMPLETED,
            new FilterUserResponseEvent($user, $request, $response)
        );

        return $response;
    }
}
