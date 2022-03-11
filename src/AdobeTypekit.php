<?php

namespace Weble\AdobeTypekit;

use Exception;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class AdobeTypekit
{
    public function __construct(
        protected Filesystem $filesystem,
        protected string $path,
        protected bool $inline,
        protected bool $fallback,
        protected string $userAgent,
        protected array $fonts,
    ) {
    }

    public function load(string $font = 'default', bool $forceDownload = false): Fonts
    {
        if (! isset($this->fonts[$font])) {
            throw new RuntimeException("Font `{$font}` doesn't exist");
        }

        $url = $this->fonts[$font];

        try {
            if ($forceDownload) {
                return $this->fetch($url);
            }

            $fonts = $this->loadLocal($url);

            if (! $fonts) {
                return $this->fetch($url);
            }

            return $fonts;
        } catch (Exception $exception) {
            if (! $this->fallback) {
                throw $exception;
            }

            return new Fonts(adoneTypekitUrl: $url);
        }
    }

    protected function loadLocal(string $url): ?Fonts
    {
        if (! $this->filesystem->exists($this->path($url, 'fonts.css'))) {
            return null;
        }

        $localizedCss = $this->filesystem->get($this->path($url, 'fonts.css'));

        return new Fonts(
            adoneTypekitUrl: $url,
            localizedUrl: $this->filesystem->url($this->path($url, 'fonts.css')),
            localizedCss: $localizedCss,
            preferInline: $this->inline,
        );
    }

    protected function fetch(string $url): Fonts
    {
        $css = Http::withHeaders(['User-Agent' => $this->userAgent])
            ->get($url)
            ->body();

        $localizedCss = $css;

        foreach ($this->extractFontUrls($css) as $fontUrl) {
            $localizedFontUrl = $this->localizeFontUrl($fontUrl);

            $this->filesystem->put(
                $this->path($url, $localizedFontUrl),
                Http::get($fontUrl)->body(),
            );

            $localizedCss = str_replace(
                $fontUrl,
                $this->filesystem->url($this->path($url, $localizedFontUrl)),
                $localizedCss,
            );
        }

        $this->filesystem->put($this->path($url, 'fonts.css'), $localizedCss);

        return new Fonts(
            adoneTypekitUrl: $url,
            localizedUrl: $this->filesystem->url($this->path($url, 'fonts.css')),
            localizedCss: $localizedCss,
            preferInline: $this->inline,
        );
    }

    protected function extractFontUrls(string $css): array
    {
        $matches = [];
        preg_match_all('/url\("(https:\/\/use.typekit.net\/[^)]+)"\)/', $css, $matches);

        return $matches[1] ?? [];
    }

    protected function localizeFontUrl(string $path): string
    {
        $extension = Str(Http::head($path)->header('Content-Type'))->replace("application/font-")->toString();
        dd($extension);
        dd(str_replace('https://use.typekit.net/', '', $path));
        dd($path, $extension);
        return implode('.', [Str::slug($path), $extension]);
    }

    protected function path(string $url, string $path = ''): string
    {
        return $this->path . '/' . substr(md5($url), 0, 10) . '/' . $path;
    }
}
