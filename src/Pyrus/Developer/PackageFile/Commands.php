<?php
namespace pear2\Pyrus\Developer\PackageFile;

use pear2\Pyrus\Developer\CoverageAnalyzer as Coverage;
class Commands
{
    function makePackageXml($frontend, $args, $options)
    {
        if (isset($args['dir'])) {
            $dir = $args['dir'];
            if (!file_exists($dir)) {
                throw new Exception('Invalid directory: ' . $dir . ' does not exist');
            }
        } else {
            $dir = getcwd();
        }
        if (!isset($args['packagename']) && file_exists($dir . '/package.xml')) {
            try {
                $testpackage = new \pear2\Pyrus\Package($dir . '/package.xml');
                $args['packagename'] = $testpackage->name;
                // if packagename isn't set, channel can't be set
                $args['channel'] = $testpackage->channel;
            } catch (\Exception $e) {
                // won't work, user has to be explicit
                throw new \pear2\Pyrus\Developer\Creator\Exception('missing first argument: PackageName');
            }
        }
        if (!isset($args['channel'])) {
            $args['channel'] = 'pear2.php.net';
        }
        echo "Creating package.xml...";
        $pear2svn = new \pear2\Pyrus\Developer\PackageFile\PEAR2SVN($dir, $args['packagename'], $args['channel'],
                                                       false, true, !$options['nocompatible']);
        if (!$options['packagexmlsetup'] && file_exists($pear2svn->path . '/packagexmlsetup.php')) {
            $options['packagexmlsetup'] = 'packagexmlsetup.php';
        }
        if ($options['packagexmlsetup']) {
            $package = $pear2svn->packagefile;
            // compatible is null if not specified
            $compatible = $pear2svn->compatiblepackagefile;
            $file = $options['packagexmlsetup'];
            $path = $pear2svn->path;
            if (!file_exists($path . '/' . $file)) {
                throw new \pear2\Pyrus\Developer\Creator\Exception(
                                    'packagexmlsetup file must be in a subdirectory of the package.xml');
            }
            $getinfo = function() use ($file, $path, $package, $compatible) {
                include $path . '/' . $file;
            };
            $getinfo();
            $pear2svn->save();
        }
        echo "done\n";
        if ($options['package']) {
            $formats = explode(',', $options['package']);
            $first = $formats[0];
            $formats = array_flip($formats);
            $formats[$first] = 1;

            $opts = array('phar' => false, 'tgz' => false, 'tar' => false, 'zip' => false);
            $opts = array_merge($opts, $formats);
            $opts['stub'] = $options['stub'];
            $opts['extrasetup'] = $options['extrasetup'];
            if (isset($args['dir'])) {
                $args = array('packagexml' => $args['dir'] . '/package.xml');
            } else {
                $args = array();
            }
            $this->package($frontend, $args, $opts);
        }
    }

    function makePECLPackage($frontend, $args, $options)
    {
        if (isset($args['dir'])) {
            $dir = $args['dir'];
            if (!file_exists($dir)) {
                throw new Exception('Invalid directory: ' . $dir . ' does not exist');
            }
        } else {
            $dir = getcwd();
        }
        $sourceextensions = array('c', 'cc', 'h', 'm4', 'w32', 're', 'y', 'l', 'frag');
        if (isset($args['extension'])) {
            $sourceextensions = array_merge($sourceextensions, $args['extension']);
        }
        if (!isset($args['packagename']) && file_exists($dir . '/package.xml')) {
            try {
                $testpackage = new \pear2\Pyrus\Package($dir . '/package.xml');
                $args['packagename'] = $testpackage->name;
                // if packagename isn't set, channel can't be set
                $args['channel'] = $testpackage->channel;
            } catch (\Exception $e) {
                // won't work, user has to be explicit
                throw new \pear2\Pyrus\Developer\Creator\Exception('missing first argument: PackageName');
            }
        }
        if (!isset($args['channel'])) {
            $args['channel'] = 'pecl.php.net';
        }
        echo "Creating package.xml...";
        $package = new \pear2\Pyrus\Developer\PackageFile\PECL($dir, $args['packagename'], $args['channel'], $sourceextensions);
        echo "done\n";
        if ($options['donotpackage']) {
            return;
        }
        if (extension_loaded('zlib')) {
            echo "Creating ", $package->name . '-' . $package->version['release'] . '.tgz ...';
            if (file_exists($dir . '/' . $package->name . '-' . $package->version['release'] . '.tgz')) {
                unlink($dir . '/' . $package->name . '-' . $package->version['release'] . '.tgz');
            }
            $phar = new \PharData($dir . '/' . $package->name . '-' . $package->version['release'] . '.tgz');
        } else {
            echo "Creating ", $package->name . '-' . $package->version['release'] . '.tar ...';
            if (file_exists($dir . '/' . $package->name . '-' . $package->version['release'] . '.tar')) {
                unlink($dir . '/' . $package->name . '-' . $package->version['release'] . '.tar');
            }
            $phar = new \PharData($dir . '/' . $package->name . '-' . $package->version['release'] . '.tar');
        }
        // add md5sum
        foreach ($package->files as $path => $file) {
            $stuff = $file->getArrayCopy();
            $stuff['attribs']['md5sum'] = md5_file($dir . '/' . $file['attribs']['name']);
            $package->files[$path] = $stuff;
        }
        $phar['package.xml'] = (string) $package;
        foreach ($package->files as $file) {
            // do automatic package-time version replacement
            $phar[$file['attribs']['name']] = str_replace('@PACKAGE_VERSION@', $package->version['release'],
                                                          file_get_contents($dir . '/' . $file['attribs']['name']));
        }
        echo "done\n";
    }

    function package($frontend, $args, $options)
    {
        $package1 = false;
        if (!isset($args['packagexml'])) {
            // first try ./package.xml
            if (file_exists('package.xml')) {
                try {
                    $package = new \pear2\Pyrus\Package(getcwd() . DIRECTORY_SEPARATOR . 'package.xml');
                } catch (\pear2\Pyrus\PackageFile\Exception $e) {
                    if ($e->getCode() != -3) {
                        throw $e;
                    }
                    if (file_exists('package2.xml')) {
                        $package = new \pear2\Pyrus\Package(getcwd() . DIRECTORY_SEPARATOR . 'package2.xml');
                        // now the creator knows to do the magic of package2.xml/package.xml
                        $package->thisIsOldAndCrustyCompatible();
                    }
                }
            }
        } else {
            $package = new \pear2\Pyrus\Package($args['packagexml']);
        }
        if ($package->isNewPackage()) {
            if (!$options['phar'] && !$options['zip'] && !$options['tar'] && !$options['tgz']) {
                // try tgz first
                if (extension_loaded('zlib')) {
                    $options['tgz'] = true;
                } else {
                    $options['tar'] = true;
                }
            }
            if ($options['phar'] && ini_get('phar.readonly')) {
                throw new \pear2\Pyrus\Developer\Creator\Exception("Cannot create phar archive, pass -dphar.readonly=0");
            }
        } else {
            if ($options['zip'] || $options['phar']) {
                echo "Zip and Phar archives can only be created for PEAR2 packages, ignoring\n";
            }
            if (extension_loaded('zlib')) {
                $options['tgz'] = true;
            } else {
                $options['tar'] = true;
            }
        }

        // get openssl cert if set, and password
        if (\pear2\Pyrus\Config::current()->openssl_cert) {
            if ('yes' == $frontend->ask('Sign package?', array('yes', 'no'), 'yes')) {
                $cert = \pear2\Pyrus\Config::current()->openssl_cert;
                if (!file_exists($cert)) {
                    throw new \pear2\Pyrus\Developer\Creator\Exception('OpenSSL certificate ' .
                        $cert . ' does not exist');
                }
                $releaser = \pear2\Pyrus\Config::current()->handle;
                $maintainers = array();
                foreach ($package->maintainer as $maintainer) {
                    $maintainers[] = $maintainer->user;
                }
                if (!strlen($releaser)) {
                    throw new \pear2\Pyrus\Developer\Creator\Exception('handle configuration variable must be from ' .
                            'package.xml (one of ' . implode(', ', $maintainers) . ')');
                }
                if (!in_array($releaser, $maintainers)) {
                    throw new \pear2\Pyrus\Developer\Creator\Exception('handle configuration variable must be from ' .
                            'package.xml (one of ' . implode(', ', $maintainers) . ')');
                }
                $passphrase = $frontend->ask('passphrase for OpenSSL PKCS#12 certificate?');
                if (!$passphrase) {
                    $passphrase = '';
                }
            } else {
                $releaser = $cert = null;
                $passphrase = '';
            }
        } else {
            $releaser = $cert = null;
            $passphrase = '';
        }

        $sourcepath = \pear2\Pyrus\Main::getSourcePath();
        if (0 !== strpos($sourcepath, 'phar://')) {
            // running from svn, assume we're in an all checkout
            $svnall = realpath($sourcepath . '/../..');
            if (!file_exists($svnall . '/Exception')) {
                throw new \pear2\Pyrus\Developer\Creator\Exception('Cannot locate pear2/Exception and friends, bailing');
            }
            $exceptionpath = $svnall . '/Exception/src';
            $autoloadpath = $svnall . '/Autoload/src';
            $multierrorspath = $svnall . '/MultiErrors/src';
        } else {
            $exceptionpath = $autoloadpath = $multierrorspath = dirname($sourcepath) .
                '/pear2';
        }
        $extras = array();
        $stub = false;
        if ($options['tgz'] && extension_loaded('zlib')) {
            $mainfile = $package->name . '-' . $package->version['release'] . '.tgz';
            $mainformat = \Phar::TAR;
            $maincompress = \Phar::GZ;
        } elseif ($options['tgz']) {
            $options['tar'] = true;
        }
        if ($options['tar']) {
            if (isset($mainfile)) {
                $extras[] = array('tar', \Phar::TAR, \Phar::NONE);
            } else {
                $mainfile = $package->name . '-' . $package->version['release'] . '.tar';
                $mainformat = \Phar::TAR;
                $maincompress = \Phar::NONE;
            }
        }
        if ($options['phar']) {
            if (isset($mainfile)) {
                $extras[] = array('phar', \Phar::PHAR, \Phar::GZ);
            } else {
                $mainfile = $package->name . '-' . $package->version['release'] . '.phar';
                $mainformat = \Phar::PHAR;
                $maincompress = \Phar::NONE;
            }
            if (!$options['stub'] && file_exists(dirname($package->archivefile) . '/stub.php')) {
                $stub = file_get_contents(dirname($package->archivefile) . '/stub.php');
            } elseif ($options['stub'] && file_exists($options['stub'])) {
                $stub = file_get_contents($options['stub']);
            }
            $stub = str_replace('@PACKAGE_VERSION' . '@', $package->version['release'], $stub);
        }
        if ($options['zip']) {
            if (isset($mainfile)) {
                $extras[] = array('zip', \Phar::ZIP, \Phar::NONE);
            } else {
                $mainfile = $package->name . '-' . $package->version['release'] . '.zip';
                $mainformat = \Phar::ZIP;
                $maincompress = \Phar::NONE;
            }
        }
        if (isset($options['outputfile'])) {
            $mainfile = $options['outputfile'];
        }
        echo "Creating ", $mainfile, "\n";
        if (null == $cert) {
            $cloner = new \pear2\Pyrus\Package\Cloner($mainfile);
            $clone = $extras;
            $extras = array();
        } else {
            foreach ($extras as $stuff) {
                echo "Creating ", $package->name, '-', $package->version['release'], '.', $stuff[0], "\n";
            }
            $clone = array();
        }
        $creator = new \pear2\Pyrus\Package\Creator(array(
                    new \pear2\Pyrus\Developer\Creator\Phar($mainfile, $stub, $mainformat, $maincompress,
                                                           $extras, $releaser, $package, $cert, $passphrase)),
                                                   $exceptionpath, $autoloadpath, $multierrorspath);
        if (!$options['extrasetup'] && file_exists(dirname($package->archivefile) . '/extrasetup.php')) {
            $options['extrasetup'] = 'extrasetup.php';
        }
        if ($options['extrasetup']) {
            // encapsulate the extrafiles inside a closure so there is no access to the variables in this function
            $getinfo = function() use ($options, $package) {
                $file = $options['extrasetup'];
                if (!file_exists(dirname($package->archivefile) . '/' . $file)) {
                    throw new \pear2\Pyrus\Developer\Creator\Exception(
                                        'extrasetup file must be in the same directory as package.xml');
                }
                include dirname($package->archivefile) . '/' . $file;
                if (!isset($extrafiles)) {
                    throw new \pear2\Pyrus\Developer\Creator\Exception(
                                        'extrasetup file must set $extrafiles variable to an array of files');
                }
                if (!is_array($extrafiles)) {
                    throw new \pear2\Pyrus\Developer\Creator\Exception(
                                        'extrasetup file must set $extrafiles variable to an array of files');
                }
                foreach ($extrafiles as $path => $file) {
                    if (is_object($file)) {
                        if ($file instanceof \pear2\Pyrus\Package) {
                            continue;
                        }
                        throw new \pear2\Pyrus\Developer\Creator\Exception(
                                            'extrasetup file object must be a \pear2\Pyrus\Package object');
                    }
                    if (!file_exists($file)) {
                        throw new \pear2\Pyrus\Developer\Creator\Exception(
                                            'extrasetup file ' . $file . ' does not exist');
                    }
                    if (!is_string($path)) {
                        throw new \pear2\Pyrus\Developer\Creator\Exception(
                                            'extrasetup file ' . $file . ' index should be the path to save in the' .
                                            ' release');
                    }
                }
                return $extrafiles;
            };
            $extrafiles = $getinfo();
        } else {
            $extrafiles = array();
        }
        $creator->render($package, $extrafiles);
        if (count($clone)) {
            foreach ($clone as $extra) {
                echo "Creating ", $package->name, '-', $package->version['release'], '.', $extra[0], "\n";
                $cloner->{'to' . $extra[0]}();
            }
        }
        echo "done\n";
    }

    function runTests($frontend, $args, $options)
    {
        if ($options['modified']) {
            if (!isset($args['path']) || !count($args['path'])) {
                $testpath = realpath(getcwd() . '/tests');
                $codepath = realpath(getcwd() . '/src');
            } else {
                $testpath = realpath($args['path'][0]);
                $codepath = realpath($args['path'][1]);
            }
            $sqlite = new Coverage\Sqlite($testpath . '/pear2coverage.db', $codepath, $testpath);
            $modified = $sqlite->getModifiedTests();
            if (!count($modified)) {
                goto dorender;
            }
        }

        if ($options['modified']) {
            $options['recursive'] = false;
            $options['coverage'] = true;
        } else {
            $modified = $args['path'];
        }
        $runner = new \pear2\Pyrus\Developer\Runphpt\Runner($options['coverage'], $options['recursive']);

        try {
            if (!$runner->runTests($modified)) {
                if ($options['modified']) {
                    echo "Tests failed - not regenerating coverage data\n";
                }
                return;
            }
        } catch (\Exception $e) {
            // tests failed
            if ($options['modified']) {
                echo "Tests failed - not regenerating coverage data\n";
                return;
            } else {
                throw $e;
            }
        }
        if (!$options['modified']) {
            return;
        }
dorender:
        $a = new Coverage\Aggregator($testpath,
                            $codepath,
                            $testpath . '/pear2coverage.db');
        $coverage = $a->retrieveProjectCoverage();
        echo "Project coverage: ", (($coverage[0] / $coverage[1]) * 100), "%\n";
    }
}