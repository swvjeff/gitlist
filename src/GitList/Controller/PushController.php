<?php

namespace GitList\Controller;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

class PushController implements ControllerProviderInterface
{
    public function connect(Application $app)
    {
        $route = $app['controllers_factory'];
        
        $route->get('{repo}/push', function($repo) use ($app) {
            
            $repository = $app['git']->getRepositoryFromName($app['git.repos'], $repo);
    
            if($remote === null) {
                $remote = $repository->getTrackedRemote();
            }
            
            if ($branch === null) {
                $branch = $repository->getHead();
            }
            
            if(empty($remote) || empty($branch))
            {
                exit("Your current branch isn't tracking a remote.");
            }
    
            $push_status = $repository->getPushStatus($remote, $branch);
            return $app['twig']->render('push.twig', array(
                'repo'           => $repo,
                'branch'         => $branch,
                'branches'       => $repository->getBranches(),
                'tags'           => $repository->getTags(),
                'push_status'    => $push_status,
                'remote'         => $remote,
                'branch'         => $branch,
            ));
        })->bind('push');
    
        /*
        $route->post('{repo}/push/{branch}', function (Request $request, $repo, $branch = '') use ($app) {
            $repository = $app['git']->getRepositoryFromName($app['git.repos'], $repo);
    
            if ($branch === null) {
                $branch = $repository->getHead();
            }
    
            $actions = $request->request->get('action');
            $do = $request->request->get('do');
            $result = '';
    
            $push_status = $repository->getPushStatus($branch);
    
            return $app['twig']->render('push.twig', array(
                'repo'           => $repo,
                'branch'         => $branch,
                'branches'       => $repository->getBranches(),
                'tags'           => $repository->getTags(),
                'push_status'    => $push_status,
            ));
        })->assert('repo', $app['util.routing']->getRepositoryRegex())
            ->assert('branch', $app['util.routing']->getBranchRegex())
            ->value('branch', null);
        */
        
        return $route;
        
    }
} 