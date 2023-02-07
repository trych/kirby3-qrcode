<?php

declare(strict_types=1);

namespace Bnomei;

use Kirby\Cms\Field;
use Kirby\Cms\File;
use Kirby\Cms\Html;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Cms\User;
use Kirby\Filesystem\F;
use Kirby\Http\Header;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Str;

final class QRCode
{
    /** @var \Endroid\QrCode\Builder\Builder */
    private $qrCode;

    /** @var array */
    private $options;
    /** @var ModelWithContent */
    private $model;

    /**
     * QRCode constructor.
     * @param array $options
     */
    public function __construct(array $options = [], $model = null)
    {
        $this->options = $options;

        $this->qrCode = \Endroid\QrCode\Builder\Builder::create();

        $text = A::get($this->options, 'Text');
        if ($text instanceof Field) {
            $text = $text->value();
        }
        $this->qrCode->data($text);

        foreach ($options as $option => $value) {
            if (method_exists($this->qrCode, $option) === false) {
                continue;
            }
            $this->qrCode->{$option}($value);
        }

        $this->model = $model;
    }

    /**
     * @return \Endroid\QrCode\QrCode
     */
    public function qrcode()
    {
        return $this->qrCode;
    }

    /**
     * @param string $name
     * @param array $attrs
     * @return string
     * @throws \Endroid\QrCode\Exception\UnsupportedExtensionException
     */
    public function html(string $name, array $attrs = []): string
    {
        $extension = ucfirst(pathinfo($name, PATHINFO_EXTENSION));
        $class = 'Endroid\\QrCode\\Writer\\' . $extension . 'Writer';
        $this->qrCode->writer(new $class());

        $result = $this->qrCode->build();
        return Html::tag('img', null, array_merge($attrs, [
            'src' => $result->getDataUri()
        ]));
    }

    /**
     * @param string $name
     * @throws \Endroid\QrCode\Exception\UnsupportedExtensionException
     */
    public function download(string $name)
    {
        // @codeCoverageIgnoreStart
        $extension = ucfirst(pathinfo($name, PATHINFO_EXTENSION));
        $class = 'Endroid\\QrCode\\Writer\\' . $extension . 'Writer';
        $this->qrCode->writer(new $class());

        $result = $this->qrCode->build();

        Header::download([
            'mime' => $result->getMimeType(),
            'name' => $name,
        ]);
        echo $result->getString();
        die(); // needed to make content type work
        // @codeCoverageIgnoreEnd
    }

    public function save(string $name, ?string $template = null, array $content = [], bool $force = false): File
    {
        $extension = ucfirst(pathinfo($name, PATHINFO_EXTENSION));
        $class = 'Endroid\\QrCode\\Writer\\' . $extension . 'Writer';
        $this->qrCode->writer(new $class());

        $result = $this->qrCode->build();
        $parent = $this->model instanceof File ? $this->model->parent() : $this->model;
        $filepath = $parent->root() . '/' . F::safeName($name);

        if (F::exists($filepath) && $force) {
            $parent->file(F::safeName($name))->delete();
        }
        if (F::exists($filepath) && !$force) {
            throw new \Exception("File '$filepath' exists. Use `force` param to overwrite. An overwrite will change Kirbys UUID and media URL.");
        }
        $filepathTMP = tempnam(sys_get_temp_dir(), md5($name));
        $result->saveToFile($filepathTMP);

        kirby()->impersonate(kirby()->users()->current()->id() ?? 'kirby');
        return $parent->createFile([
            'source'   => $filepathTMP,
            'filename' => $name,
            'template' => $template,
            'content'  => $content,
        ], move: true); // will delete file
    }

    /**
     * @param string|null $template
     * @param mixed|null $model
     * @return string
     */
    public static function query(string $template = null, $model = null): string
    {
        $page = null;
        $file = null;
        $user = kirby()->user();
        if ($model && $model instanceof Page) {
            $page = $model;
        } elseif ($model && $model instanceof File) {
            $file = $model;
        } elseif ($model && $model instanceof User) {
            $user = $model;
        }
        return Str::template($template, [
            'kirby' => kirby(),
            'site' => kirby()->site(),
            'page' => $page,
            'file' => $file,
            'user' => $user,
            'model' => $model ? get_class($model) : null,
        ]);
    }

    public static function hashForApiCall(string $id): string
    {
        return sha1(__DIR__ . 'qrcode' . $id . date('ymd'));
    }
}
