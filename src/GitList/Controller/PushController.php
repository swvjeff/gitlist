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
        
        $route->match('{repo}/push', function(Request $request, $repo) use ($app) {

            $repository = $app['git']->getRepositoryFromName($app['git.repos'], $repo);
            $currentBranch = $repository->getHead();
            $tracked = $repository->getTrackedRemote();
            $message = '';
            
            if(empty($currentBranch))
            {
                exit("Git repository not on a valid branch.");
            }

            if(empty($tracked['remote']) || empty($tracked['branch']))
            {
                /// TODO: Think about showing a dropdown of available branches (or a textbox so user can enter new branch)
                exit("Local branch isn't tracking a remote branch.");
            }

            $do = $request->request->get('do');

            if($do === 'Push')
            {
                $message = $repository->push($tracked['remote'], $tracked['branch']);
            }

            $commits = $repository->getUnpushedCommits($tracked['remote'], $tracked['branch']);
            $categorized = array();
            
            foreach ($commits as $commit) {
                $date = $commit->getDate();
                $date = $date->format('m/d/Y');
                $categorized[$date][] = $commit;
            }
            
            return $app['twig']->render('push.twig', array(
                'repo'           => $repo,
                'branch'         => $currentBranch,
                'branches'       => $repository->getBranches(),
                'tags'           => $repository->getTags(),
                'commits'        => $categorized,
                'remote'         => $tracked['remote'],
                'remoteBranch'   => $tracked['branch'],
                'message'        => $message,
            ));
        })->method('GET|POST')->bind('push');
    
        
        return $route;
        
    }
} 