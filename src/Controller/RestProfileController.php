<?php

// /src/App/Controller/RestProfileController.php

namespace App\Controller;

use App\Entity\User;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\Controller\Annotations\Get;
use FOS\RestBundle\Controller\Annotations\RouteResource;
use FOS\RestBundle\Controller\FOSRestController;
use FOS\RestBundle\Routing\ClassResourceInterface;
use FOS\RestBundle\View\View;
use FOS\UserBundle\Event\FilterUserResponseEvent;
use FOS\UserBundle\Event\FormEvent;
use FOS\UserBundle\Event\GetResponseUserEvent;
use FOS\UserBundle\FOSUserEvents;
use Nelmio\ApiDocBundle\Annotation\Model;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Swagger\Annotations as SWG;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @RouteResource("profile", pluralize=false)
 */
class RestProfileController extends FOSRestController implements ClassResourceInterface
{
    /**
     * This is the content of FOSUB ProfileController, all we did is to adapt it
     * To return JSON response.
     * @ParamConverter("user", options={"mapping": {"user": "userId"}})
     * @Rest\Put(
     *     path="/profile/{user}/edit",
     *     name="api_edit",
     *     requirements={"user"="\d+"}
     * )
     * @Rest\View(StatusCode=204)
     * @SWG\Response(
     *     response=204,
     *     description="The user corresponding to the user_id is edited",
     *     @Model(type=User::class)
     * )
     * @SWG\Parameter(
     *     name="user",
     *     in="path",
     *     type="integer",
     *     description="The user_id of the user"
     * )
     * @SWG\Parameter(
     *     name="user",
     *     in="body",
     *     @Model(type=User::class),
     *     description="The user to register"
     * )
     * @SWG\Tag(name="Profile")
     * @param Request $request
     * @param User $user
     * @return View|\Symfony\Component\Form\FormInterface
     */
    public function putAction(Request $request, User $user)
    {
        /** @var $dispatcher \Symfony\Component\EventDispatcher\EventDispatcherInterface */
        $dispatcher = $this->get('event_dispatcher');
        /** @var $formFactory \FOS\UserBundle\Form\Factory\FactoryInterface */
        $formFactory = $this->get('fos_user.profile.form.factory');
        /** @var $userManager \FOS\UserBundle\Model\UserManagerInterface */
        $userManager = $this->get('fos_user.user_manager');
        $event = new GetResponseUserEvent($user, $request);
        $dispatcher->dispatch(FOSUserEvents::PROFILE_EDIT_INITIALIZE, $event);
        if (null !== $event->getResponse()) {
            return $event->getResponse();
        }
        $form = $formFactory->createForm([
            'csrf_protection' => false,
            'allow_extra_fields' => true, //here we allow extra-field because we need the token field
        ]);
        $form->setData($user);
        $form->submit($request->request->all());
        if (!$form->isValid()) {
            return $form; //here we return the error converted by FOSREST
        }
        $event = new FormEvent($form, $request);
        $dispatcher->dispatch(FOSUserEvents::PROFILE_EDIT_SUCCESS, $event);
        $userManager->updateUser($user);
        // there was no override
        if (null === $response = $event->getResponse()) {
            return $this->routeRedirectView(
                'api_profile',
                ['user' => $user->getId()],
                Response::HTTP_NO_CONTENT
            );
        }
        $dispatcher->dispatch(FOSUserEvents::PROFILE_EDIT_COMPLETED, new FilterUserResponseEvent($user, $request, $response));

        return $this->routeRedirectView(
            'api_profile',
            ['user' => $user->getId()],
            Response::HTTP_NO_CONTENT
        );
    }

    /**
     * Returns user corresponding to the user_id
     * @ParamConverter("user", options={"mapping": {"user": "userId"}})
     * @Get(
     *     path="/profile/{user}",
     *     name="api_profile",
     *     requirements={"user"="\d+"}
     * )
     * @SWG\Response(
     *     response=200,
     *     description="Returns user corresponding to the user_id",
     *     @Model(type=User::class)
     * )
     * @SWG\Parameter(
     *     name="user",
     *     in="path",
     *     type="integer",
     *     description="The user_id of the user"
     * )
     * @SWG\Tag(name="Profile")
     * @param Request $request
     * @param User $user
     * @return Response
     */
    public function getAction(Request $request, User $user)
    {
        $data = $this->get('jms_serializer')->serialize($user, 'json');
        $response = new Response($data);
        $response->setSharedMaxAge(300);
        $response->headers->addCacheControlDirective('must-revalidate', true);
        $response->setEtag($user);
        if ($user !== $this->getUser()) {
            throw new AccessDeniedHttpException('You cannot access to this information');
        }
        // Check that the Response is not modified for the given Request
        if ($response->isNotModified($request)) {
            // return the 304 Response immediately
            return $response;
        }
        return $response;
    }
}
