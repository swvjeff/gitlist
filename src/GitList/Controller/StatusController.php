<?php

namespace GitList\Controller;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

class StatusController implements ControllerProviderInterface
{
    public function connect(Application $app)
    {
        $route = $app['controllers_factory'];
        
        $route->get('{repo}/status/{branch}', function($repo, $branch) use ($app) {
            $repository = $app['git']->getRepositoryFromName($app['git.repos'], $repo);
            
            if ($branch === null) {
                $branch = $repository->getHead();
            }
            
            $files = $repository->getStatus($branch);

            return $app['twig']->render('status.twig', array(
                'repo'           => $repo,
                'branch'         => $branch,
                'branches'       => $repository->getBranches(),
                'tags'           => $repository->getTags(),
                'unstaged'       => $files['unstaged'],
                'staged'         => $files['staged'],
            ));
        })->assert('repo', $app['util.routing']->getRepositoryRegex())
            ->assert('branch', $app['util.routing']->getBranchRegex())
            ->value('branch', null)
            ->bind('status');

        
        $route->post('{repo}/status/{branch}', function (Request $request, $repo, $branch = '') use ($app) {
            $repository = $app['git']->getRepositoryFromName($app['git.repos'], $repo);
            
            if ($branch === null) {
                $branch = $repository->getHead();
            }

            $actions = $request->request->get('action');
            $do = $request->request->get('do');
            $result = '';
            
            /// Stage / Unstage routine
            if(in_array($do, array('Stage Files', 'Unstage Files'))) {
                $files = $repository->getStatus($branch);
                
                $files = array_merge($files['staged'], $files['unstaged']);
    
                $staged = $unstaged = 0;
                foreach($files as $file) {
                    $hash = sha1($file['filename']);
                    switch($actions[$hash]) {
                        case 'Stage':
                            $repository->stageFile($file['filename']);
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
            
            if($do === 'Commit Files') {
                $comments = $request->request->get('comments');
                if(!empty($comments)) {
                    $result = $repository->commit($branch, $comments);
                }
            }
            
            $files = $repository->getStatus($branch);

            return $app['twig']->render('status.twig', array(
                'repo'             => $repo,
                'branch'           => $branch,
                'branches'         => $repository->getBranches(),
                'tags'             => $repository->getTags(),
                'unstaged'         => $files['unstaged'],
                'staged'           => $files['staged'],
                'message'          => $result,
            ));
        })->assert('repo', $app['util.routing']->getRepositoryRegex())
            ->assert('branch', $app['util.routing']->getBranchRegex())
            ->value('branch', null);
        
        return $route;
    }
} 