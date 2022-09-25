<?php

namespace Messi\Media;

use Illuminate\Contracts\Filesystem\FileExistsException;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\FilesystemException;
use Messi\Media\Http\Resources\FileResource;
use Messi\Media\Models\MediaFile;
use Messi\Media\Repositories\Interfaces\MediaFileInterface;
use Messi\Media\Repositories\Interfaces\MediaFolderInterface;
use Messi\Media\Services\ThumbnailService;
use Messi\Media\Services\UploadsManager;
use Exception;
use File;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Image;
use Mimey\MimeTypes;
use Storage;
use Throwable;
use Validator;

class Media
{

    /**
     * @var array
     */
    protected array $permissions = [];

    /**
     * @var UploadsManager
     */
    protected UploadsManager $uploadManager;

    /**
     * @var MediaFileInterface
     */
    protected MediaFileInterface $fileRepository;

    /**
     * @var MediaFolderInterface
     */
    protected MediaFolderInterface $folderRepository;

    /**
     * @var ThumbnailService
     */
    protected ThumbnailService $thumbnailService;

    /**
     * @param MediaFileInterface $fileRepository
     * @param MediaFolderInterface $folderRepository
     * @param UploadsManager $uploadManager
     * @param ThumbnailService $thumbnailService
     */
    public function __construct(
        MediaFileInterface $fileRepository,
        MediaFolderInterface $folderRepository,
        UploadsManager $uploadManager,
        ThumbnailService $thumbnailService
    ) {
        $this->fileRepository = $fileRepository;
        $this->folderRepository = $folderRepository;
        $this->uploadManager = $uploadManager;
        $this->thumbnailService = $thumbnailService;

        $this->permissions = config('core.media.media.permissions', []);
    }

    /**
     * @return string
     * @throws Throwable
     */
    public function renderHeader(): string
    {
        $urls = $this->getUrls();
        return view('core/media::header', compact('urls'))->render();
    }

    /**
     * Get all URLs
     * @return array
     */
    public function getUrls(): array
    {
        return [
            'base_url'                 => url(''),
            'base'                     => route('admin.media.index'),
            'get_media'                => route('admin.media.list'),
            'create_folder'            => route('admin.media.folders.create'),
            'popup'                    => route('admin.media.popup'),
            'download'                 => route('admin.media.download'),
            'upload_file'              => route('admin.media.files.upload'),
            'get_breadcrumbs'          => route('admin.media.breadcrumbs'),
            'global_actions'           => route('admin.media.global_actions'),
            'media_upload_from_editor' => route('admin.media.files.upload.from.editor'),
            'download_url'             => route('admin.media.download_url'),
        ];
    }

    /**
     * @return string
     * @throws Throwable
     */
    public function renderFooter(): string
    {
        return view('core/media::footer')->render();
    }

    /**
     * @return string
     * @throws Throwable
     */
    public function renderContent(): string
    {
        return view('core/media::content')->render();
    }

    /**
     * @param string|array $data
     * @param null $message
     * @return JsonResponse
     */
    public function responseSuccess($data, $message = null): JsonResponse
    {
        return response()->json([
            'error'   => false,
            'data'    => $data,
            'message' => $message,
        ]);
    }

    /**
     * @param string $message
     * @param array $data
     * @param int | null $code
     * @param int $status
     * @return JsonResponse
     */
    public function responseError(
        string $message,
        array $data = [],
        int | null $code = null,
        int $status = 200
    ): JsonResponse
    {
        return response()->json([
            'error'   => true,
            'message' => $message,
            'data'    => $data,
            'code'    => $code,
        ], $status);
    }

    /**
     * @param string $url
     * @return array|null
     */
    public function getAllImageSizes(string $url): array|null
    {
        $images = [];
        foreach ($this->getSizes() as $size) {
            $readableSize = explode('x', $size);
            $images = $this->getImageUrl($url, $readableSize);
        }

        return $images;
    }

    /**
     * @return array
     */
    public function getSizes(): array
    {
        return config('core.media.media.sizes', []);
    }

    /**
     * @param string|null $url
     * @param null $size
     * @param bool $relativePath
     * @param null $default
     * @return Application|UrlGenerator|string|null
     */
    public function getImageUrl(
        string| null $url,
        $size = null,
        bool $relativePath = false,
        $default = null
    ): Application|UrlGenerator|string|null
    {
        if (empty($url)) {
            return $default;
        }

        if (empty($size) || $url == '__value__') {
            if ($relativePath) {
                return $url;
            }
            return $this->url($url);
        }

        if ($url == $this->getDefaultImage()) {
            return url($url);
        }

        if ($size &&
            array_key_exists($size, $this->getSizes()) &&
            $this->canGenerateThumbnails($this->getMimeType($url))
        ) {
            $url = str_replace(
                File::name($url) . '.' . File::extension($url),
                File::name($url) . '-' . $this->getSize($size) . '.' . File::extension($url),
                $url
            );
        }

        if ($relativePath) {
            return $url;
        }

        if ($url == '__image__') {
            return $this->url($default);
        }

        return $this->url($url);
    }

    /**
     * @param string $path
     * @return string
     */
    public function url(string $path): string
    {
        if (Str::contains($path, 'https://') || Str::contains($path, 'http://')) {
            return $path;
        }

        if (config('filesystems.default') === 'do_spaces' && (int)setting('media_do_spaces_cdn_enabled')) {
            $customDomain = setting('media_do_spaces_cdn_custom_domain');

            if ($customDomain) {
                return $customDomain . '/' . ltrim($path, '/');
            }

            return str_replace('.digitaloceanspaces.com', '.cdn.digitaloceanspaces.com', Storage::url($path));
        }

        return Storage::url($path);
    }

    /**
     * @param bool $relative
     * @return string
     */
    public function getDefaultImage(bool $relative = false): string
    {
        $default = config('core.media.media.default_image');

        if (setting('media_default_placeholder_image')) {
            $default = $this->url(setting('media_default_placeholder_image'));
        }

        if ($relative) {
            return $default;
        }

        return $default ? url($default) : $default;
    }

    /**
     * @param string $name
     * @return ?string
     */
    public function getSize(string $name): ?string
    {
        return config('core.media.media.sizes.' . $name);
    }

    /**
     * @param MediaFile|Model $file
     * @return bool
     */
    public function deleteFile(MediaFile|Model $file): bool
    {
        $this->deleteThumbnails($file);

        return Storage::delete($file->url);
    }

    /**
     * @param MediaFile|Model $file
     * @return bool
     */
    public function deleteThumbnails(MediaFile|Model $file): bool
    {
        if (!$file->canGenerateThumbnails()) {
            return false;
        }

        $filename = pathinfo($file->url, PATHINFO_FILENAME);

        $files = [];
        foreach ($this->getSizes() as $size) {
            $files[] = str_replace($filename, $filename . '-' . $size, $file->url);
        }

        return Storage::delete($files);
    }

    /**
     * @return array
     */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    /**
     * @param array $permissions
     */
    public function setPermissions(array $permissions)
    {
        $this->permissions = $permissions;
    }

    /**
     * @param string $permission
     */
    public function removePermission(string $permission)
    {
        Arr::forget($this->permissions, $permission);
    }

    /**
     * @param string $permission
     */
    public function addPermission(string $permission)
    {
        $this->permissions[] = $permission;
    }

    /**
     * @param string $permission
     * @return bool
     */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions);
    }

    /**
     * @param array $permissions
     * @return bool
     */
    public function hasAnyPermission(array $permissions): bool
    {
        $hasPermission = false;
        foreach ($permissions as $permission) {
            if (in_array($permission, $this->permissions)) {
                $hasPermission = true;
                break;
            }
        }

        return $hasPermission;
    }

    /**
     * @param string $name
     * @param int $width
     * @param int $height
     * @return $this
     */
    public function addSize(string $name, int $width, int $height): self
    {
        config(['core.media.media.sizes.' . $name => $width . 'x' . $height]);

        return $this;
    }

    /**
     * @param string $name
     * @return $this
     */
    public function removeSize(string $name): self
    {
        $sizes = $this->getSizes();
        Arr::forget($sizes, $name);

        config(['core.media.media.sizes' => $sizes]);

        return $this;
    }

    /**
     * @param Request $request
     * @param int $folderId
     * @param string | null $folderName
     * @param string $fileInput
     * @return Application|ResponseFactory|JsonResponse|Response
     * @throws FilesystemException
     */
    public function uploadFromEditor(
        Request $request,
        int $folderId = 0,
        string | null $folderName = null,
        string $fileInput = 'upload'
    ): Application|ResponseFactory|JsonResponse|Response
    {
        $validator = Validator::make($request->all(), [
            'upload' => 'required|image|mimes:jpg,jpeg,png,webp',
        ]);

        if ($validator->fails()) {
            return response('<script>alert("' . trans('core/media::media.can_not_detect_file_type') . '")</script>')
                ->header('Content-Type', 'text/html');
        }

        $folderName = $folderName ?: $request->input('upload_type');

        $result = $this->handleUpload($request->file($fileInput), $folderId, $folderName);

        if ($result['error'] == false) {
            $file = $result['data'];
            if (!$request->input('CKEditorFuncNum')) {
                return response()->json([
                    'fileName' => File::name($this->url($file->url)),
                    'uploaded' => 1,
                    'url'      => $this->url($file->url),
                ]);
            }

            return response('<script>window.parent.CKEDITOR.tools.callFunction("' . $request->input('CKEditorFuncNum') .
                '", "' . $this->url($file->url) . '", "");</script>')
                ->header('Content-Type', 'text/html');
        }

        return response('<script>alert("' . Arr::get($result, 'message') . '")</script>')
            ->header('Content-Type', 'text/html');
    }

    /**
     * @param UploadedFile | null $fileUpload
     * @param int $folderId
     * @param string|null $folderSlug
     * @param bool $skipValidation
     * @return JsonResponse|array
     * @throws FilesystemException
     */
    public function handleUpload(
        UploadedFile | null $fileUpload,
        int $folderId = 0,
        string|null $folderSlug = null,
        bool $skipValidation = false
    ): array | JsonResponse
    {
        if (request()->input('path')) {
            $folderId = $this->handleTargetFolder($folderId, request()->input('path'));
        }
        if (!$fileUpload) {
            return [
                'error'   => true,
                'message' => trans('core/media::media.can_not_detect_file_type'),
            ];
        }

        $allowedMimeTypes = config('core.media.media.allowed_mime_types');

        $request = request();

        if (!config('core.media.media.chunk.enabled')) {
            $request->merge(['uploaded_file' => $fileUpload]);

            if (!$skipValidation) {
                $validator = Validator::make($request->all(), [
                    'uploaded_file' => 'required|mimes:' . $allowedMimeTypes,
                ]);

                if ($validator->fails()) {
                    return [
                        'error'   => true,
                        'message' => $validator->getMessageBag()->first(),
                    ];
                }
            }

            $maxSize = $this->getServerConfigMaxUploadFileSize();

            if ($fileUpload->getSize() / 1024 > (int)$maxSize) {
                return [
                    'error'   => true,
                    'message' => trans('core/media::media.file_too_big', ['size' => human_file_size($maxSize)]),
                ];
            }
        }

        try {
            $file = $this->fileRepository->getModel();

            $fileExtension = $fileUpload->getClientOriginalExtension();

            if (!$skipValidation && !in_array(strtolower($fileExtension), explode(',', $allowedMimeTypes))) {
                return [
                    'error'   => true,
                    'message' => trans('core/media::media.can_not_detect_file_type'),
                ];
            }

            if ($folderId == 0 && !empty($folderSlug)) {
                $folder = $this->folderRepository->getFirstBy(['media_folders.slug' => $folderSlug]);

                if (!$folder) {
                    $folder = $this->folderRepository->createOrUpdate([
                        'user_id'   => Auth::check() ? Auth::id() : 0,
                        'name'      => $this->folderRepository->createName($folderSlug, 0),
                        'slug'      => $this->folderRepository->createSlug($folderSlug, 0),
                        'parent_id' => 0,
                    ]);
                }

                $folderId = $folder->id;
            }

            $file->name = $this->fileRepository->createName(
                File::name($fileUpload->getClientOriginalName()),
                $folderId
            );

            $folderPath = $this->folderRepository->getFullPath($folderId);

            $fileName = $this->fileRepository->createSlug(
                $file->name,
                $fileExtension,
                Storage::path($folderPath)
            );

            $filePath = $fileName;

            if ($folderPath) {
                $filePath = $folderPath . '/' . $filePath;
            }

            $content = File::get($fileUpload->getRealPath());

            $this->uploadManager->saveFile($filePath, $content, $fileUpload);

            $data = $this->uploadManager->fileDetails($filePath);

            if (!$skipValidation && empty($data['mime_type'])) {
                return [
                    'error'   => true,
                    'message' => trans('core/media::media.can_not_detect_file_type'),
                ];
            }

            $file->url = $data['url'];
            $file->size = $data['size'];
            $file->mime_type = $data['mime_type'];
            $file->folder_id = $folderId;
            $file->user_id = Auth::check() ? Auth::id() : 0;
            $file->options = $request->input('options', []);
            $file = $this->fileRepository->createOrUpdate($file);

            $this->generateThumbnails($file);

            return [
                'error' => false,
                'data'  => new FileResource($file),
            ];
        } catch (Exception $exception) {
            return [
                'error'   => true,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * Returns a file size limit in bytes based on the PHP upload_max_filesize and post_max_size
     * @return float|int
     */
    public function getServerConfigMaxUploadFileSize(): float|int
    {
        // Start with post_max_size.
        $maxSize = $this->parseSize(ini_get('post_max_size'));

        // If upload_max_size is less, then reduce. Except if upload_max_size is
        // zero, which indicates no limit.
        $uploadMax = $this->parseSize(ini_get('upload_max_filesize'));
        if ($uploadMax > 0 && $uploadMax < $maxSize) {
            $maxSize = $uploadMax;
        }

        return $maxSize;
    }

    /**
     * @param int $size
     * @return float - bytes
     */
    public function parseSize($size): float
    {
        $unit = preg_replace('/[^bkmgtpezy]/i', '', $size); // Remove the non-unit characters from the size.
        $size = preg_replace('/[^0-9\.]/', '', $size); // Remove the non-numeric characters from the size.
        if ($unit) {
            // Find the position of the unit in the ordered string which is the power of magnitude to multiply a kilobyte by.
            return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
        }

        return round($size);
    }

    /**
     * @param MediaFile $file
     * @return bool
     * @throws FileExistsException
     * @throws FileNotFoundException|FilesystemException
     */
    public function generateThumbnails(MediaFile $file): bool
    {
        if (!$file->canGenerateThumbnails()) {
            return false;
        }

        foreach ($this->getSizes() as $size) {
            $readableSize = explode('x', $size);
            $this->thumbnailService
                ->setImage($this->getRealPath($file->url))
                ->setSize($readableSize[0], $readableSize[1])
                ->setDestinationPath(File::dirname($file->url))
                ->setFileName(File::name($file->url) . '-' . $size . '.' . File::extension($file->url))
                ->save();
        }
        if (setting('media_watermark_enabled', config('core.media.media.watermark.enabled'))) {
            $image = Image::make($this->getRealPath($file->url));
            $watermark = Image::make($this->getRealPath(setting('media_watermark_source',
                config('core.media.media.watermark.source'))));

            // 10% less then an actual image (play with this value)
            // Watermark will be 10 less then the actual width of the image
            $watermarkSize = round(
                $image->width() * setting('media_watermark_size',
                    config('core.media.media.watermark.size') / 100),
                2);

            // Resize watermark width keep height auto
            $watermark
                ->resize($watermarkSize, null, function ($constraint) {
                    $constraint->aspectRatio();
                })
                ->opacity(setting('media_watermark_opacity', config('core.media.media.watermark.opacity')));

            $image->insert($watermark,
                setting('media_watermark_position', config('core.media.media.watermark.position')),
                setting('watermark_position_x', config('core.media.media.watermark.x')),
                setting('watermark_position_y', config('core.media.media.watermark.y'))
            );

            $destinationPath = sprintf('%s/%s', trim(File::dirname($file->url), '/'),
                File::name($file->url) . '.' . File::extension($file->url));

            $this->uploadManager->saveFile($destinationPath, $image->stream()->__toString());
        }

        return true;
    }

    /**
     * @param string $url
     * @return string
     */
    public function getRealPath(string $url): string
    {
        switch (config('filesystems.default')) {
            case 'local':
            case 'public':
                return Storage::path($url);
            case 's3':
            case 'do_spaces':
                return Storage::url($url);
        }

        return Storage::path($url);
    }

    /**
     * @param string $mimeType
     * @return bool
     */
    public function isImage(string $mimeType): bool
    {
        return Str::startsWith($mimeType, 'image/');
    }

    /**
     * @return bool
     */
    public function isUsingCloud(): bool
    {
        return !in_array(config('filesystems.default'), ['local', 'public']);
    }

    /**
     * @param string $url
     * @param int $folderId
     * @param string|null $folderSlug
     * @param string|null $defaultMimetype
     * @return JsonResponse|array|null
     * @throws FilesystemException
     */
    public function uploadFromUrl(
        string $url,
        int $folderId = 0,
        ?string $folderSlug = null,
        string $defaultMimetype = null
    ): JsonResponse|array|null
    {
        if (empty($url)) {
            return [
                'error'   => true,
                'message' => trans('core/media::media.url_invalid'),
            ];
        }

        $info = pathinfo($url);

        try {
            $contents = file_get_contents($url);
        } catch (Exception $exception) {
            return [
                'error'   => true,
                'message' => $exception->getMessage(),
            ];
        }

        if (empty($contents)) {
            return null;
        }

        $path = '/tmp';
        if (!File::isDirectory($path)) {
            File::makeDirectory($path, 0755);
        }

        $path = $path . '/' . $info['basename'];
        file_put_contents($path, $contents);


        $mimeType = $this->getMimeType($url);

        if (empty($mimeType)) {
            $mimeType = $defaultMimetype;
        }

        $fileName = File::name($info['basename']);
        $fileExtension = File::extension($info['basename']);
        if (empty($fileExtension)) {
            $mimeTypeDetection = new MimeTypes;

            $fileExtension = $mimeTypeDetection->getExtension($mimeType);
        }

        $fileUpload = new UploadedFile($path, $fileName . '.' . $fileExtension, $mimeType, null, true);

        $result = $this->handleUpload($fileUpload, $folderId, $folderSlug);

        File::delete($path);

        return $result;
    }

    /**
     * @param string $path
     * @param int $folderId
     * @param string|null $folderSlug
     * @param null $defaultMimetype
     * @return JsonResponse|array
     * @throws FilesystemException
     */
    public function uploadFromPath(string $path, int $folderId = 0, ?string $folderSlug = null, $defaultMimetype = null): JsonResponse|array
    {
        if (empty($path)) {
            return [
                'error'   => true,
                'message' => trans('core/media::media.path_invalid'),
            ];
        }

        $mimeType = $this->getMimeType($path);

        if (empty($mimeType)) {
            $mimeType = $defaultMimetype;
        }

        $fileName = File::name($path);
        $fileExtension = File::extension($path);
        if (empty($fileExtension)) {
            $mimeTypeDetection = new MimeTypes;

            $fileExtension = $mimeTypeDetection->getExtension($mimeType);
        }

        $fileUpload = new UploadedFile($path, $fileName . '.' . $fileExtension, $mimeType, null, true);

        return $this->handleUpload($fileUpload, $folderId, $folderSlug);
    }

    /**
     * @return string
     */
    public function getUploadPath(): string
    {
        return public_path('storage');
    }

    /**
     * @return string
     */
    public function getUploadURL(): string
    {
        return str_replace('/index.php', '', url('storage'));
    }

    /**
     * @return $this
     */
    public function setUploadPathAndURLToPublic(): self
    {
        return $this;
    }

    /**
     * @param string $url
     * @return mixed|string|null
     */
    public function getMimeType(string $url): mixed
    {
        if (!$url) {
            return null;
        }

        $mimeTypeDetection = new MimeTypes;

        return $mimeTypeDetection->getMimeType(File::extension($url));
    }

    /**
     * @param string $mimeType
     * @return bool
     */
    public function canGenerateThumbnails(string $mimeType): bool
    {
        return Media::isImage($mimeType) && !in_array($mimeType, ['image/svg+xml', 'image/x-icon']);
    }

    /**
     *
     * @param string $folderSlug
     * @param int $parentId
     * @return mixed
     */
    public function createFolder(string $folderSlug, int $parentId = 0): mixed
    {
        $folder = $this->folderRepository->getFirstBy(['media_folders.slug' => $folderSlug]);


        if (!$folder) {
            $folder = $this->folderRepository->createOrUpdate([
                'user_id'   => Auth::check() ? Auth::id() : 0,
                'name'      => $this->folderRepository->createName($folderSlug, 0),
                'slug'      => $this->folderRepository->createSlug($folderSlug, 0),
                'parent_id' => $parentId,
            ]);
        }

        return $folder->id;
    }

    /**
     * @param int $folderId
     * @param string $filePath
     * @return string
     */
    public function handleTargetFolder(int $folderId = 0, string $filePath = ''): string
    {
        if (str_contains($filePath, '/')) {
            $paths = explode('/', $filePath);
            array_pop($paths);
            foreach ($paths as $folder) {
                $folderId = $this->createFolder($folder, $folderId);
            }
        }

        return $folderId;
    }
}
