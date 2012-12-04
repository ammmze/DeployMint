<?php

class DeployMintProjectTools
{
    private static $git = 'git';

    public static function setGit($git)
    {
        self::$git = $git;
    }

    public static function getGit()
    {
        return self::$git;
    }

    public static function isGitRepo($dir)
    {
        $res = self::git("branch", $dir);
        return !self::isFatalResponse($res);
    }

    public static function getRemoteNames($dir)
    {
        $list = self::git('remote', $dir);
        $listArr = preg_split('/[\r\n\s\t\*]+/', $list);
        $clean = array();
        foreach($listArr as $li) {
            $name = trim($li);
            if (strlen($name)>0 && !in_array($name, $clean)) {
                $clean[] = $name;
            }
        }
        return $clean;
    }

    public static function remoteExists($dir, $remoteName='origin')
    {
        return in_array($remoteName, self::getRemoteNames($dir));
    }

    public static function connectedToRemote($dir, $remoteName='origin')
    {
        $res = self::fetch($dir, $remoteName);
        return !self::isFatalResponse($res);
    }

    public static function setRemote($dir, $url, $remoteName='origin')
    {
        if (strlen($url) == 0 || $url == null) {
            self::git("remote remove $remoteName", $dir);
        } else {
            if (self::remoteExists($dir, $remoteName)) {
                self::git("remote set-url $remoteName $url", $dir);
            } else {
                self::git("remote add $remoteName $url", $dir);
            }
        }
        
    }

    public static function fetch($dir, $remoteName='origin')
    {
        if (self::remoteExists($dir, $remoteName)) {
            return self::git("fetch $remoteName --prune", $dir);
        } else {
            return null;
        }
    }

    public static function getAllBranches($dir)
    {
        $branchList = self::git('branch -a', $dir);
        $branchList = preg_replace('/\(no branch\)/', '', $branchList);
        $branches = preg_split('/[\r\n\s\t\*]+/', $branchList);
        $clean = array();
        foreach($branches as $b) {
            $name = trim(preg_replace('/(remotes\/[^\/]*\/)?/', '', $b));
            if (strlen($name)>0 && !in_array($name, $clean)) {
                $clean[] = $name;
            }
        }
        return $clean;
    }

    public static function branchExists($dir, $branch)
    {
        return in_array($branch, self::getAllbranches($dir));
    }

    public static function git($cmd, $dir)
    {
        $git = self::$git;
        return DeployMintTools::mexec("$git $cmd 2>&1", $dir);
    }

    public static function isFatalResponse($response)
    {
        return preg_match('/fatal/', $response);
    }

    public static function deleteBranch($dir, $branch, $remote='origin')
    {
        $out = self::deleteLocalBranch($dir, $branch);
        return $out . ' :: ' . self::deleteRemoteBranch($dir, $branch, $remote);
    }

    public static function deleteRemoteBranch($dir, $branch, $remote='origin')
    {
        return self::git("push $remote --delete $branch", $dir);
    }

    public static function deleteLocalBranch($dir, $branch)
    {
        return self::git("branch -D $branch", $dir);
    }

    public static function pushAllBranches($dir, $remote='origin')
    {
        return self::git("push $remote --all", $dir);
    }

    public static function pushBranch($dir, $branch, $remote='origin')
    {
        return self::git("push $remote $branch", $dir);
    }

    public static function createTag($dir, $name, $force=false)
    {
        $params = array();
        if ($force) {
            $params[] = '--force';
        }
        $params = implode(' ', $params);
        return self::git("tag $name $params", $dir);
    }

    public static function pushTags($dir, $remote='origin')
    {
        return self::git("push $remote --tags", $dir);
    }

    public static function checkout($dir, $branch, $remote='origin')
    {
        self::git("fetch $remote", $dir);
        return self::git("checkout -t -f $remote/$branch", $dir);
    }

    public static function detach($dir)
    {
        return self::git("checkout HEAD@{0}", $dir);
    }
}