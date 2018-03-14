<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AppBundle\Controller;

use AppBundle\Entity\Comment;
use AppBundle\Entity\Post;
use AppBundle\Entity\Tag;
use AppBundle\Events;
use AppBundle\Form\CommentType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\ORM\Query\ResultSetMapping;
/**
 * Controller used to manage blog contents in the public part of the site.
 *
 * @Route("/blog")
 *
 * @author Ryan Weaver <weaverryan@gmail.com>
 * @author Javier Eguiluz <javier.eguiluz@gmail.com>
 */
class BlogController extends Controller
{
    /**
     * @Route("/", defaults={"page": "1", "_format"="html"}, name="blog_index")
     * @Route("/tag/{tagSlug}", defaults={"tagSlug": "0", "page": "1", "_format"="html"}, name="blog_index_tag")
     * @Route("/rss.xml", defaults={"page": "1", "_format"="xml"}, name="blog_rss")
     * @Route("/page/{page}", defaults={"_format"="html"}, requirements={"page": "[1-9]\d*"}, name="blog_index_paginated")
     * @Method("GET")
     * @Cache(smaxage="10")
     * @ParamConverter("post", options={"mapping": {"tagSlug": "slug"}})
     *
     * NOTE: For standard formats, Symfony will also automatically choose the best
     * Content-Type header for the response.
     * See http://symfony.com/doc/current/quick_tour/the_controller.html#using-formats
     */
    public function indexAction($page, $_format, $tagSlug=0)
    {
        $posts = $this->getDoctrine()->getRepository(Post::class)->findLatest($page);
        if ($tagSlug != 0)
        {
            $rsm = new ResultSetMapping();
            $em = $this->getDoctrine()->getEntityManager();
            $connection = $em->getConnection();
            $rsm->addScalarResult('id', 'id');
            $rsm->addScalarResult('author_id', 'author_id');
            $rsm->addScalarResult('title', 'title');
            $rsm->addScalarResult('slug', 'slug');
            $rsm->addScalarResult('summary', 'summary');
            $rsm->addScalarResult('content', 'content');
            $rsm->addScalarResult('publishedAt', 'publishedAt');
           // $rsm->addEntityResult('Post', 'p');
            $statement = $em->createNativeQuery('SELECT p.* 
            FROM symfony_demo_post p LEFT JOIN symfony_demo_post_tag pt ON p.id = pt.post_id
            WHERE pt.tag_id = ?', $rsm);
            $statement->setParameter(1, $tagSlug);
            $posts = $statement->getResult();
            // $searchTag = $this->getDoctrine()->getRepository(Tag::class)->find($tagSlug);
            // var_dump($searchTag);

            // $filteredPosts = $this->getDoctrine()->getRepository(Post::class)->find();
            /*$posts = [];
            foreach ($results as $row)
            {
                $posts[] = $row;
            }*/

            return $this->render('blog/tagresults.'.$_format.'.twig', ['posts' => $posts]);

        } else {
            return $this->render('blog/index.'.$_format.'.twig', ['posts' => $posts]);
        }
        echo "de tagslug is" . $tagSlug;
        // Every template name also has two extensions that specify the format and
        // engine for that template.
        // See https://symfony.com/doc/current/templating.html#template-suffix
        return $this->render('blog/index.'.$_format.'.twig', ['posts' => $posts]);
    }

    /**
     * @Route("/tags/{tagSlug}", name="filter_tags")
     * @Method("GET")
     * @Security("is_granted('IS_AUTHENTICATED_FULLY')")
     * @ParamConverter("post", options={"mapping": {"tagSlug": "slug"}})
     *
     * NOTE: The ParamConverter mapping is required because the route parameter
     * (postSlug) doesn't match any of the Doctrine entity properties (slug).
     * See http://symfony.com/doc/current/bundles/SensioFrameworkExtraBundle/annotations/converters.html#doctrine-converter
     */
    public function filterTagsAction($tagSlug)
    {
        /*$em = $this->getDoctrine()->getManager();

         $tag = $this->getDoctrine()->getRepository(Tag::class)->find($tagId);

        $posts = $this->getDoctrine()->getRepository(Post::class)->findAll();

        for($i = 0; $i < count($posts); $i++) {
            if($i == $tagSlug) {
                $postTag = $posts[$i]->getTags();
            }
        }
        


        return $this->render('blog/index.html.twig', ['posts' => $posts] );*/
    }

    /**
     * @Route("/posts/{slug}", name="blog_post")
     * @Method("GET")
     *
     * NOTE: The $post controller argument is automatically injected by Symfony
     * after performing a database query looking for a Post with the 'slug'
     * value given in the route.
     * See http://symfony.com/doc/current/bundles/SensioFrameworkExtraBundle/annotations/converters.html
     */
    public function postShowAction(Post $post)
    {
        // Symfony provides a function called 'dump()' which is an improved version
        // of the 'var_dump()' function. It's useful to quickly debug the contents
        // of any variable, but it's not available in the 'prod' environment to
        // prevent any leak of sensitive information.
        // This function can be used both in PHP files and Twig templates. The only
        // requirement is to have enabled the DebugBundle.
        if ('dev' === $this->getParameter('kernel.environment')) {
            dump($post, $this->get('security.token_storage')->getToken()->getUser(), new \DateTime());
        }

        return $this->render('blog/post_show.html.twig', ['post' => $post]);
    }
    
    /**
     * @Route("/comment/{postSlug}/new", name="comment_new")
     * @Method("POST")
     * @Security("is_granted('IS_AUTHENTICATED_FULLY')")
     * @ParamConverter("post", options={"mapping": {"postSlug": "slug"}})
     *
     * NOTE: The ParamConverter mapping is required because the route parameter
     * (postSlug) doesn't match any of the Doctrine entity properties (slug).
     * See http://symfony.com/doc/current/bundles/SensioFrameworkExtraBundle/annotations/converters.html#doctrine-converter
     */
    public function commentNewAction(Request $request, Post $post)
    {
        $form = $this->createForm(CommentType::class);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var Comment $comment */
            $comment = $form->getData();
            $comment->setAuthor($this->getUser());
            $comment->setPost($post);

            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($comment);
            $entityManager->flush();

            // When triggering an event, you can optionally pass some information.
            // For simple applications, use the GenericEvent object provided by Symfony
            // to pass some PHP variables. For more complex applications, define your
            // own event object classes.
            // See http://symfony.com/doc/current/components/event_dispatcher/generic_event.html
            $event = new GenericEvent($comment);

            // When an event is dispatched, Symfony notifies it to all the listeners
            // and subscribers registered to it. Listeners can modify the information
            // passed in the event and they can even modify the execution flow, so
            // there's no guarantee that the rest of this controller will be executed.
            // See http://symfony.com/doc/current/components/event_dispatcher.html
            $this->get('event_dispatcher')->dispatch(Events::COMMENT_CREATED, $event);

            return $this->redirectToRoute('blog_post', ['slug' => $post->getSlug()]);
        }

        return $this->render('blog/comment_form_error.html.twig', [
            'post' => $post,
            'form' => $form->createView(),
        ]);
    }

    /**
     * This controller is called directly via the render() function in the
     * blog/post_show.html.twig template. That's why it's not needed to define
     * a route name for it.
     *
     * The "id" of the Post is passed in and then turned into a Post object
     * automatically by the ParamConverter.
     *
     * @param Post $post
     *
     * @return Response
     */
    public function commentFormAction(Post $post)
    {
        $form = $this->createForm(CommentType::class);

        return $this->render('blog/_comment_form.html.twig', [
            'post' => $post,
            'form' => $form->createView(),
        ]);
    }

}
