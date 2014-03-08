<?php

namespace GitList\Controller;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;

class StatusController implements ControllerProviderInterface
{
    public function connect(Application $app)
    {
        $route = $app['controllers_factory'];

        $route->get('{repo}/status', function($repo) use ($app) {
            
            $repository = $app['git']->getRepositoryFromName($app['git.repos'], $repo);
            $branch = $repository->getHead();
            
            $files = $repository->getStatus();
            
            return $app['twig']->render('status.twig', array(
                'repo'           => $repo,
                'branch'         => $branch,
                'branches'       => $repository->getBranches(),
                'tags'           => $repository->getTags(),
                'unstaged'       => $files['unstaged'],
                'staged'         => $files['staged'],
            ));
        })->assert('repo', $app['util.routing']->getRepositoryRegex())
            ->bind('status');

        
        $route->post('{repo}/status', function (Request $request, $repo) use ($app) {
            $repository = $app['git']->getRepositoryFromName($app['git.repos'], $repo);
            
            $branch = $repository->getHead();

            $actions = $request->request->get('action');
            $do = $request->request->get('do');
            $result = '';

            $username = '';
            $token = $app['security']->getToken();
            if ($token !== null) {
                $user = $token->getUser();
                $username = $user->getUsername();
            }

            $gitName = '';
            $gitEmail = '';
            if(!empty($username))
            {
                $gitInfo = $app['config']['users'][$username]['git'];
                if(is_array($gitInfo) && array_key_exists('name', $gitInfo) && !empty($gitInfo['name']) && array_key_exists('email', $gitInfo) && !empty($gitInfo['email']))
                {
                    $gitName = $gitInfo['name'];
                    $gitEmail = $gitInfo['email'];
                }
            }

            /// Stage / Unstage routine
            if(in_array($do, array('Stage Files', 'Unstage Files'))) {
                $files = $repository->getStatus();
                
                $files = array_merge($files['staged'], $files['unstaged']);
    
                $staged = $unstaged = 0;
                foreach($files as $file) {
                    $hash = sha1($file['filename']);
                    switch($actions[$hash]) {
                        case 'Stage':
                            $result .= $repository->stageFile($file['filename']);
                            $staged++;
                            break;
                        case 'Unstage':
                            $repository->unstageFile($file['filename']);
                            $unstaged++;
                            break;
                    }
                }
                
                if($staged > 0) {
                    $result .= $staged.' file'.($staged > 1 ? 's' : '').' staged. ';
                }
                if($unstaged > 0) {
                    $result .= $unstaged.' file'.($unstaged > 1 ? 's' : '').' unstaged. ';
                }
            }
            
            if(empty($message) && $do === 'Commit Files') {
                $comments = $request->request->get('comments');
                if(!empty($comments)) {
                    $result = $repository->commit($branch, $comments, $gitName, $gitEmail);
                }
            }
            
            $files = $repository->getStatus();

            return $app['twig']->render('status.twig', array(
                'repo'             => $repo,
                'branch'           => $branch,
                'branches'         => $repository->getBranches(),
                'tags'             => $repository->getTags(),
                'unstaged'         => $files['unstaged'],
                'staged'           => $files['staged'],
                'message'          => $result,
            ));
        })->assert('repo', $app['util.routing']->getRepositoryRegex());
        
        return $route;
    }
} 