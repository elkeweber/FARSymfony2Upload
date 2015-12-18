<?php

namespace FARSymfony2UploadBundle\Lib;

use Symfony\Component\DependencyInjection\ContainerInterface as Container;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\FileBag;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;

class FARSymfony2UploadLib
{
    protected $options;
    protected $session;
    private $id_session;
    private $parameters;
    private $container;
    private $request;
    private $trans;
    private $local_filesystem;
    private $remote_filesystem;

    /**
     * @param Container $container
     * @param RequestStack $request_stack
     * @param mixed $options
     */
    public function __construct(Container $container, RequestStack $request_stack, Session $session, $options = null)
    {
        $this->session = $session;
        $this->container = $container;
        $this->request = $request_stack->getCurrentRequest();
        $this->options = $options;
        $this->parameters = $this->container->getParameter('far_upload_bundle');
        $this->trans = $this->container->get('translator');

        $this->local_filesystem = $this
            ->container
            ->get('oneup_flysystem.mount_manager')
            ->getFilesystem('local_filesystem');

        $this->remote_filesystem = $this
            ->container
            ->get('oneup_flysystem.mount_manager')
            ->getFilesystem('remote_filesystem');
    }

    /**
     * @param $id_session
     *
     * @return mixed
     */
    public function processUpload($id_session)
    {
        $this->id_session = $id_session;

        $response['data'] = array('files' => '');
        /* @var FileBag $filebag */
        foreach ($this->request->files as $filebag) {
            /* @var UploadedFile $file */
            foreach ($filebag as $file) {
                $properties = $this->getFileProperties($file);
                $validFile = $this->validateFile($properties);
                if ($validFile[0] == true) {
                    $file->move($properties['temp_dir'], $properties['name_uid']);
                    $this->createThumbnail($properties);
                }
                $response['data'] = $this->getjQueryUploadResponse($properties, $validFile);
            }
        }

        $response['headers'] = $this->getHeadersJSON();

        return $response;
    }

    /**
     * @param $action
     *
     * @return bool
     */
    public function evalDelete($action)
    {
        if (($this->request->getMethod() === 'POST' && $this->request->request->get('_method') == 'DELETE') ||
            ($this->request->getMethod() === 'DELETE' && $action == 'DELETE')
        ) {
            return true;
        }
        return false;
    }

    /**
     * @param $id_session
     * @param $php_session
     * @param $image
     *
     * @return array()
     */
    public function processDelete($id_session, $php_session, $image)
    {
        $path = $this->parameters['temp_path'].'/'.$php_session.'/'.$id_session.'/';
        $response = $this->deleteFile($path, $image);

        return $response;
    }

    /**
     * @param $id_session
     *
     * @return array()
     */
    public function saveUpload($id_session)
    {
        $php_session = $this->session->getId();

        $local_route = $this->getRoutes('local');
        $this->syncFiles();
    }

    /**
     * @param $php_session
     * @param $id_session
     *
     * @return array()
     */
    public function getListFilesLocal($php_session, $id_session)
    {
        $files = $this->local_filesystem->listContents($php_session.'/'.$id_session);
        return $this->mappingFileSystem($files);
    }

    /**
     * @param array $files
     * @return array()
     */
    private function mappingFileSystem($files)
    {
        /*
        0 = {array} [8]
         type = "file"
         path = "d0i8nvm9p9h3v9k08vn8jl1qs7/123/Captura de pantalla de 2015-12-04 10:00:44.png"
         timestamp = 1450435418
         size = 43157
         dirname = "d0i8nvm9p9h3v9k08vn8jl1qs7/123"
         basename = "Captura de pantalla de 2015-12-04 10:00:44.png"
         extension = "png"
         filename = "Captura de pantalla de 2015-12-04 10:00:44"
        */

        $filesNew['type'] = $files['type'];
        $filesNew['timestamp'] = $files['timestamp'];
        $filesNew['size'] = $files['size'];
        $filesNew['pathOrig'] = $files['path'];
        $filesNew['dirnameOrig'] = $files['dirname'];
        $filesNew['basenameOrig'] = $files['basename'];
        $filesNew['extensionOrig'] = $files['extension'];
        $filesNew['filenameOrig'] =$files['filename'];

        $filesNew['pathDest'] = $files['path'];
        $filesNew['dirnameDest'] = $files['dirname'];
        $filesNew['basenameDest'] = $files['basename'];
        $filesNew['extensionDest'] = $files['extension'];
        $filesNew['filenameDest'] = $files['filename'];

        return $filesNew;
    }

    /**
     * @param $files
     *
     * @return array()
     */
    public function syncFilesLocalRemote($files)
    {
        foreach ($files as $file) {
            $contents = $this->local_filesystem->read($file['pathOrig']);
//            $this->remote_filesystem->write()
        }

//        $filesystem->delete($path.$image);
//        $filesystem->delete($path.$this->getFileNameOrThumbnail($image, true));

    }

    /**
     * @param UploadedFile $file
     *
     * @return array()
     */
    private function getFileProperties($file)
    {
        $properties = array();

        $properties['original_name'] = $file->getClientOriginalName();
        $properties['extension'] = $file->guessExtension();

        $properties['name'] = $this->getFileNameOrThumbnail($properties['original_name'], false);
        $properties['name_uid'] = $properties['original_name'];
        $properties['thumbnail_name'] = $this->getFileNameOrThumbnail($properties['original_name'], true);
        $properties['size'] = $file->getClientSize();
        $properties['maxfilesize'] = $file->getMaxFilesize();
        $properties['mimetype'] = $file->getMimeType();
        $properties['session'] = $this->session->getId();
        $properties['id_session'] = $this->id_session;
        $properties['temp_dir'] = $this->parameters['temp_path'].'/'.
            $this->session->getId().'/'.
            $properties['id_session'];

        return $properties;
    }

    /**
     * @param array $properties
     *
     * @return array()
     */
    private function validateFile($properties)
    {
        $result = array(true, 'Always fine');

        if (!$this->validateFileSize($properties)) {
            $result = array(false, $this->trans->trans(
                'File.size.exceed.maximum.allowed'
            ));
        } else {
            if (!$this->validateFileExtension($properties)) {
                $result = array(false, $this->trans->trans(
                    'File.type.not.allowed'
                ));
            }
        }
        if (!$this->validateUploadMaxFiles($properties)) {
            $result = array(false, $this->trans->trans(
                'Too.much.files.for.upload',
                array('%max_files_upload%' => $this->parameters['max_files_upload'])
            ));
        }

        return $result;
    }

    /**
     * @param array $properties
     *
     * @return bool
     */
    private function validateFileSize($properties)
    {
        if ($properties['size'] > $this->parameters['max_file_size']) {
            return false;
        }
        return true;
    }

    /**
     * @param array $properties
     *
     * @return bool
     */
    private function validateFileExtension($properties)
    {

        if (array_search($properties['extension'], $this->parameters['file_extensions_allowed'])
        ) {
            return true;
        }
        return false;
    }

    /**
     * @param array $properties
     *
     * @return bool
     */
    private function validateUploadMaxFiles($properties)
    {
        $finder = new Finder();
        $countFiles = $finder->files()
            ->in($this->parameters['temp_path'].'/'.
                        $properties['session'].'/'.
                        $properties['id_session'])
            ->count();

        /* max_files_upload * 2 because the thumbnails */
        if ($countFiles < $this->parameters['max_files_upload']*2) {
            return true;
        }
        return false;
    }

    /**
     * @return array $header
     */
    private function getHeadersJSON()
    {
        $server_accept = $this->request->server->get('HTTP_ACCEPT');

        if ($server_accept && strpos($server_accept, 'application/json') !== false) {
            $type_header = 'application/json';
        } else {
            $type_header = 'text/plain';
        }
        return array(
            'Vary' => 'Accept',
            'Content-type' => $type_header,
            'Pragma' => 'no-cache',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Content-Disposition' => 'inline; filename="files.json"',
            'X-Content-Type-Options' => 'nosniff',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'OPTIONS, HEAD, GET, POST, PUT, DELETE',
            'Access-Control-Allow-Headers' => 'X-File-Name, X-File-Type, X-File-Size',
        );
    }

    /**
     * @param $path
     * @param $file
     *
     * @return string
     */
    private function deleteFile($path, $file)
    {
        // TODO: Borrar miniaturas PS
        $filesystem = new Filesystem();
        $fileTemp = $path.$file;
        $thumbnail = $path.$this->getFileNameOrThumbnail($file, true);

        if ($filesystem->exists($fileTemp)) {
            $filesystem->remove($fileTemp);
        }
        if ($filesystem->exists($thumbnail)) {
            $filesystem->remove($thumbnail);
        }
        $response[0][$fileTemp] = true;

        return $response;
    }

    /**
     * @param string $filename
     * @param string $thumbnail
     *
     * @return string
     */
    private function getFileNameOrThumbnail($filename, $thumbnail)
    {
        $original_name = pathinfo($filename);
        $name = $original_name['filename'];
        $extension = $original_name['extension'];

        if ($thumbnail) {
            return $name.'_'.$this->parameters['thumbnail_size'].'.'.$extension;
        } else {
            return $name;
        }
    }

    /**
     * @param array $properties
     * @param array $validFile
     *
     * @return array()
     */
    private function getJQueryUploadResponse($properties, $validFile)
    {
        $response[0]['name'] = $properties['name'];
        $response[0]['size'] = $properties['size'];
        if ($validFile[0]) {
            $response[0]['url'] = $this->getURLResponse($properties);
            $response[0]['thumbnailUrl'] = $this->getTumbnailURLResponse($properties);
            $response[0]['deleteUrl'] = $response[0]['url'].'_DELETE';
            $response[0]['deleteType'] = 'DELETE';
            $response[0]['type'] = $properties['mimetype'];
        } else {
            $response[0]['error'] = $validFile[1];
        }

        return $response;
    }

    /**
     * @param $properties
     *
     * @return string
     */
    private function getURLResponse($properties)
    {
        return $this->request->getBaseUrl().'/tmp/'.
        $properties['session'].'/'.
        $properties['id_session'].'/'.
        $properties['name_uid'];
    }

    /**
     * @param $properties
     *
     * @return string
     */
    private function getTumbnailURLResponse($properties)
    {
        return $this->request->getBaseUrl().'/tmp/'.
        $properties['session'].'/'.
        $properties['id_session'].'/'.
        $properties['thumbnail_name'];
    }

    /**
     * @param $properties
     */
    private function createThumbnail($properties)
    {
        // TODO: Generar miniaturas PS
        $thumbnail_size = explode('x', $this->parameters['thumbnail_size']);
        $imagine = $this->getImagineEngine();

        $size = new \Imagine\Image\Box($thumbnail_size[0], $thumbnail_size[1]);
        $mode = \Imagine\Image\ImageInterface::THUMBNAIL_INSET;

        $imagine->open($properties['temp_dir'].'/'.$properties['name_uid'])
            ->thumbnail($size, $mode)
            ->save($properties['temp_dir'].'/'.$properties['thumbnail_name']);
    }

    /**
     * @return \Imagine\Gd\Imagine|\Imagine\Gmagick\Imagine|\Imagine\Imagick\Imagine
     */
    private function getImagineEngine()
    {
        switch ($this->parameters['thumbnail_driver']) {
            case 'gd':
                $imagine = new \Imagine\Gd\Imagine();
                break;
            case 'gmagik':
                $imagine = new \Imagine\Gmagick\Imagine();
                break;
            default:
                $imagine = new \Imagine\Imagick\Imagine();
        }

        return $imagine;
    }

}
