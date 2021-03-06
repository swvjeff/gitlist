<?php

namespace GitList\Git;

use Gitter\Repository as BaseRepository;
use Gitter\Model\Commit\Commit;
use Gitter\Model\Commit\Diff;
use Gitter\PrettyFormat;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;

class Repository extends BaseRepository
{
    /**
     * Return true if the repo contains this commit.
     *
     * @param $commitHash Hash of commit whose existence we want to check
     * @return boolean Whether or not the commit exists in this repo
     */
    public function hasCommit($commitHash)
    {
        $logs = $this->getClient()->run($this, "show $commitHash");
        $logs = explode("\n", $logs);

        return strpos($logs[0], 'commit') === 0;
    }

    /**
     * Get the current branch, returning a default value when HEAD is detached.
     */
    public function getHead($default = null)
    {
        $client = $this->getClient();

        return parent::getHead($client->getDefaultBranch());
    }

    /**
     * Show the data from a specific commit
     *
     * @param  string $commitHash Hash of the specific commit to read data
     * @return array  Commit data
     */
    public function getCommit($commitHash)
    {
        $logs = $this->getClient()->run($this,
                  "show --pretty=format:\"<item><hash>%H</hash>"
                . "<short_hash>%h</short_hash><tree>%T</tree><parents>%P</parents>"
                . "<author>%an</author><author_email>%ae</author_email>"
                . "<date>%at</date><commiter>%cn</commiter><commiter_email>%ce</commiter_email>"
                . "<commiter_date>%ct</commiter_date>"
                . "<message><![CDATA[%s]]></message>"
                . "<body><![CDATA[%b]]></body>"
                . "</item>\" $commitHash"
        );

        $xmlEnd = strpos($logs, '</item>') + 7;
        $commitInfo = substr($logs, 0, $xmlEnd);
        $commitData = substr($logs, $xmlEnd);
        $logs = explode("\n", $commitData);

        // Read commit metadata
        $format = new PrettyFormat;
        $data = $format->parse($commitInfo);
        $commit = new Commit;
        $commit->importData($data[0]);

        if ($commit->getParentsHash()) {
            $command = 'diff ' . $commitHash . '~1..' . $commitHash;
            $logs = explode("\n", $this->getClient()->run($this, $command));
        }

        $commit->setDiffs($this->readDiffLogs($logs));

        return $commit;
    }

    /**
     * Blames the provided file and parses the output
     *
     * @param  string $file File that will be blamed
     * @return array  Commits hashes containing the lines
     */
    public function getBlame($file)
    {
        $blame = array();
        $logs = $this->getClient()->run($this, "blame --root -sl $file");
        $logs = explode("\n", $logs);

        $i = 0;
        $previousCommit = '';
        foreach ($logs as $log) {
            if ($log == '') {
                continue;
            }

            preg_match_all("/([a-zA-Z0-9]{40})\s+.*?([0-9]+)\)(.+)/", $log, $match);

            $currentCommit = $match[1][0];
            if ($currentCommit != $previousCommit) {
                ++$i;
                $blame[$i] = array(
                    'line' => '',
                    'commit' => $currentCommit,
                    'commitShort' => substr($currentCommit, 0, 8)
                );
            }

            $blame[$i]['line'] .= PHP_EOL . $match[3][0];
            $previousCommit = $currentCommit;
        }

        return $blame;
    }

    /**
     * Read diff logs and generate a collection of diffs
     *
     * @param  array $logs Array of log rows
     * @return array Array of diffs
     */
    public function readDiffLogs(array $logs)
    {
        $diffs = array();
        $lineNumOld = 0;
        $lineNumNew = 0;
        foreach ($logs as $log) {
            # Skip empty lines
            if ($log == "") {
                continue;
            }

            if ('diff' === substr($log, 0, 4)) {
                if (isset($diff)) {
                    $diffs[] = $diff;
                }

                $diff = new Diff;
                if (preg_match('/^diff --[\S]+ a\/?(.+) b\/?/', $log, $name)) {
                    $diff->setFile($name[1]);
                }
                continue;
            }

            if ('index' === substr($log, 0, 5)) {
                $diff->setIndex($log);
                continue;
            }

            if ('---' === substr($log, 0, 3)) {
                $diff->setOld($log);
                continue;
            }

            if ('+++' === substr($log, 0, 3)) {
                $diff->setNew($log);
                continue;
            }

            // Handle binary files properly.
            if ('Binary' === substr($log, 0, 6)) {
                $m = array();
                if (preg_match('/Binary files (.+) and (.+) differ/', $log, $m)) {
                    $diff->setOld($m[1]);
                    $diff->setNew("    {$m[2]}");
                }
            }

            if (!empty($log)) {
                switch ($log[0]) {
                    case "@":
                        // Set the line numbers
                        preg_match('/@@ -([0-9]+)/', $log, $matches);
                        $lineNumOld = $matches[1] - 1;
                        $lineNumNew = $matches[1] - 1;
                        break;
                    case "-":
                        $lineNumOld++;
                        break;
                    case "+":
                        $lineNumNew++;
                        break;
                    default:
                        $lineNumOld++;
                        $lineNumNew++;
                }
            } else {
                $lineNumOld++;
                $lineNumNew++;
            }

            if (isset($diff)) {
                $diff->addLine($log, $lineNumOld, $lineNumNew);
            }
        }

        if (isset($diff)) {
            $diffs[] = $diff;
        }

        return $diffs;
    }

    /**
     * Show the repository commit log with pagination
     *
     * @access public
     * @return array Commit log
     */
    public function getPaginatedCommits($file = null, $page = 0)
    {
        $page = 15 * $page;
        $pager = "--skip=$page --max-count=15";
        $command =
                  "log $pager --pretty=format:\"<item><hash>%H</hash>"
                . "<short_hash>%h</short_hash><tree>%T</tree><parents>%P</parents>"
                . "<author>%an</author><author_email>%ae</author_email>"
                . "<date>%at</date><commiter>%cn</commiter>"
                . "<commiter_email>%ce</commiter_email>"
                . "<commiter_date>%ct</commiter_date>"
                . "<message><![CDATA[%s]]></message></item>\"";

        if ($file) {
            $command .= " $file";
        }

        try {
            $logs = $this->getPrettyFormat($command);
        } catch (\RuntimeException $e) {
            return array();
        }

        foreach ($logs as $log) {
            $commit = new Commit;
            $commit->importData($log);
            $commits[] = $commit;
        }

        return $commits;
    }

    public function searchCommitLog($query)
    {
        $query = escapeshellarg($query);
        $command =
              "log --grep={$query} --pretty=format:\"<item><hash>%H</hash>"
            . "<short_hash>%h</short_hash><tree>%T</tree><parents>%P</parents>"
            . "<author>%an</author><author_email>%ae</author_email>"
            . "<date>%at</date><commiter>%cn</commiter>"
            . "<commiter_email>%ce</commiter_email>"
            . "<commiter_date>%ct</commiter_date>"
            . "<message><![CDATA[%s]]></message></item>\"";

        try {
            $logs = $this->getPrettyFormat($command);
        } catch (\RuntimeException $e) {
            return array();
        }

        foreach ($logs as $log) {
            $commit = new Commit;
            $commit->importData($log);
            $commits[] = $commit;
        }

        return $commits;
    }

    public function searchTree($query, $branch)
    {
        $query = escapeshellarg($query);

        try {
            $results = $this->getClient()->run($this, "grep -I --line-number {$query} $branch");
        } catch (\RuntimeException $e) {
            return false;
        }

        $results = explode("\n", $results);

        foreach ($results as $result) {
            if ($result == '') {
                continue;
            }

            preg_match_all('/([\w-._]+):([^:]+):([0-9]+):(.+)/', $result, $matches, PREG_SET_ORDER);

            $data['branch'] = $matches[0][1];
            $data['file']   = $matches[0][2];
            $data['line']   = $matches[0][3];
            $data['match']  = $matches[0][4];

            $searchResults[] = $data;
        }

        return $searchResults;
    }
    
    public function getTrackedRemote()
    {
        $info = array(
            'remote' => '',
            'branch' => '',
        );
        try
        {
            $remote = trim($this->getClient()->run($this, "rev-parse --abbrev-ref --symbolic-full-name @{u}"));
            preg_match(":^([^/]+)/(.+)$:", $remote, $m);
            $info['remote'] = $m[1];
            $info['branch'] = $m[2];
        }
        catch(\RuntimeException $e) { }
        return $info;
    }
    
    public function push($remote, $remoteBranch)
    {
        try {
            $message = $this->getClient()->run($this, "push {$remote} {$remoteBranch}");
        }
        catch(\RuntimeException $e) { 
            $message = "There was an error pushing to {$remote}/{$remoteBranch}. Please make sure your web server has access to your git repository. And that you have manually accepted the server's RSA key fingerprint. Error: ".$e->getMessage();
        }
        return $message;
    }
    
    public function getUnpushedCommits($remote, $remoteBranch)
    {
        $commits = $return = array();
        
        ini_set('xdebug.var_display_max_depth', 5);
        ini_set('xdebug.var_display_max_children', 256);
        ini_set('xdebug.var_display_max_data', 1024);
        
        /// Grab each COMMIT that hasn't yet been pushed to $remote/$remoteBranch 
        $cherry = $this->getClient()->run($this, "cherry -v {$remote}/{$remoteBranch} HEAD");
        if(!empty($cherry))
        {
            foreach(explode("\n", $cherry) as $c)
            {
                if(preg_match(":^\+ (.{40}) (.+)$:", $c, $m))
                {
                    $hash = $m[1];
                    $commits[$hash] = $this->getCommit($hash);
                }
            }
        }

        return $commits;
    }
    
    public function checkoutBranch($branch)
    {
        return $this->getClient()->run($this, 'checkout '.$branch);
    }
    
    /*
        Possible status letters are:
        A: addition of a file
        C: copy of a file into a new one
        D: deletion of a file
        M: modification of the contents or mode of a file
        R: renaming of a file
        T: change in the type of the file
        U: file is unmerged (you must complete the merge before it can be committed)
        X: "unknown" change type (most probably a bug, please report it)
     */
    public function getStatus()
    {
        $files = array(
          'staged' => array(),
          'unstaged' => array(),
        );
        
        /// Get the status, clean and convert to array
        $statuses = explode("\n",trim($this->getClient()->run($this, 'status --porcelain'), "\n\r"));
        
        foreach($statuses as $s)
        {
            if(empty($s))
            {
                continue;
            }
            
            preg_match(":^(.)(.) \"?([^\"]+)\"?$:", $s, $m);
            $filename = $m[3];
            $full_path = $this->getPath() . '/' . $filename;

            if(empty($filename) || !file_exists($full_path))
            {
                continue;
            }

            $full_path = $this->getPath() . '/' . $filename;
            
            $staged = !in_array($m[1], array(" ","?"));
            $unstaged = $m[2] !== ' ';

            $file_info = array(
                'filename' => $filename,
                'status' => $m[2],
                'modification' => date("M j G:i:s",filemtime($full_path)),
                'hash' => sha1($filename),
                'type' => filetype($full_path),
            );
            
            /// Add it to the appropriate array (it can be both "staged" and "unstaged")
            if($staged) 
            {
                $files['staged'][] = $file_info;
            }
            if($unstaged)
            {
                $files['unstaged'][] = $file_info;
            }
        }
        
        return $files;
    }
    
    public function stageFile($filename)
    {
        return $this->getClient()->run($this, 'add -v ' . $filename);
    }
    
    public function unstageFile($filename)
    {
        return $this->getClient()->run($this, 'reset HEAD -q ' . $filename);
    }
    
    public function commit($branch, $comments, $name = '', $email = '')
    {
        $author = '';
        /// Custom author (via users.yml)?
        if(!empty($name) && !empty($email))
        {
            $author = ' --author="'.$name.' <'.$email.'>"';
        }
        /// Otherwise, $HOME environment var needs to be set. Let's try and figure it out if it's not
        elseif(!$this->isHomeDirSet())
        {
            $home = $this->getHomeDir();
            if(empty($home))
            {
                return "Couldn't find HOME environment variable. Commit unsuccessful.";
            }
            putenv('HOME='.$home);
        }
        return $this->getClient()->run($this, 'commit -m "'.$comments.'"'.$author);
    }
    
    public function getHomeDir()
    {
        $process = new Process("echo ~");
        $process->run();
        return trim($process->getOutput());
    }
    
    public function isHomeDirSet()
    {
        $process = new Process("echo \$HOME");
        $process->run();
        return strlen(trim($process->getOutput())) > 0;
    }

    public function getAuthorStatistics($branch)
    {
        $logs = $this->getClient()->run($this, 'log --pretty=format:"%an||%ae" ' . $branch);

        if (empty($logs)) {
            throw new \RuntimeException('No statistics available');
        }

        $logs = explode("\n", $logs);
        $logs = array_count_values($logs);
        arsort($logs);

        foreach ($logs as $user => $count) {
            $user = explode('||', $user);
            $data[] = array('name' => $user[0], 'email' => $user[1], 'commits' => $count);
        }

        return $data;
    }

    public function getStatistics($branch)
    {
        // Calculate amount of files, extensions and file size
        $logs = $this->getClient()->run($this, 'ls-tree -r -l ' . $branch);
        $lines = explode("\n", $logs);
        $files = array();
        $data['extensions'] = array();
        $data['size'] = 0;
        $data['files'] = 0;

        foreach ($lines as $key => $line) {
            if (empty($line)) {
                unset($lines[$key]);
                continue;
            }

            $files[] = preg_split("/[\s]+/", $line);
        }

        foreach ($files as $file) {
            if ($file[1] == 'blob') {
                $data['files']++;
            }

            if (is_numeric($file[3])) {
                $data['size'] += $file[3];
            }

            if (($pos = strrpos($file[4], '.')) !== false) {
                $extension = substr($file[4], $pos);

                if (($pos = strrpos($extension, '/')) === false) {
                    $data['extensions'][] = $extension;
                }
            }
        }

        $data['extensions'] = array_count_values($data['extensions']);
        arsort($data['extensions']);

        return $data;
    }

    /**
     * Create a TAR or ZIP archive of a git tree
     *
     * @param string $tree   Tree-ish reference
     * @param string $output Output File name
     * @param string $format Archive format
     */
    public function createArchive($tree, $output, $format = 'zip')
    {
        $fs = new Filesystem;
        $fs->mkdir(dirname($output));
        $this->getClient()->run($this, "archive --format=$format --output=$output $tree");
    }

    /**
     * Return true if $path exists in $branch; return false otherwise.
     *
     * @param string $commitish Commitish reference; branch, tag, SHA1, etc.
     * @param string $path      Path whose existence we want to verify.
     *
     * GRIPE Arguably belongs in Gitter, as it's generally useful functionality.
     * Also, this really may not be the best way to do this.
     */
    public function pathExists($commitish, $path)
    {
        $output = $this->getClient()->run($this, "ls-tree $commitish '$path'");

        if (strlen($output) > 0) {
            return true;
        }

        return false;
    }
}

