<?php
/**
 * Corporate logo for licensed XRFlow Softphone desks.
 *
 * The Hub is free and stores the image. Any perpetual, annual, or monthly
 * seat enrolled here shows it. This is not a Softphone setting.
 *
 * Copyright (C) 2026 XRFlow
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
namespace FreePBX\modules;

trait XrflowCorporateLogo {

	public const LOGO_MIN_PX = 256;
	public const LOGO_MIN_DPI = 144;
	public const LOGO_MAX_BYTES = 524288;

	public function corporateLogoRequirements(): array {
		return [
			'types' => ['PNG (image/png)', 'SVG (image/svg+xml)'],
			'png' => sprintf(
				'Square PNG, at least %d×%d pixels, %d DPI or higher, %d KB maximum. 512×512 at 144 DPI is the recommended export. The toolbar draws the mark at 28×28 CSS pixels and the loading screen at 96×96.',
				self::LOGO_MIN_PX,
				self::LOGO_MIN_PX,
				self::LOGO_MIN_DPI,
				(int) (self::LOGO_MAX_BYTES / 1024)
			),
			'svg' => sprintf(
				'Square SVG (viewBox or equal width and height), no scripts or event handlers, %d KB maximum. SVG has no DPI; it stays sharp at any size.',
				(int) (self::LOGO_MAX_BYTES / 1024)
			),
			'rejected' => 'JPG, GIF, WebP, ICO, and PDF are not accepted.',
			'license' => 'Any licensed Softphone seat (perpetual, annual, or monthly) enrolled with this Hub shows the logo. Hub is free. There is no upload inside the Softphone.',
		];
	}

	/**
	 * @return array{ok:bool,error?:string,message?:string}
	 */
	public function saveCorporateLogoUpload(array $file, string $companyName): array {
		if (!isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
			return ['ok' => false, 'error' => 'no_file', 'message' => 'Choose a PNG or SVG file.'];
		}
		if ((int) $file['error'] !== UPLOAD_ERR_OK) {
			return ['ok' => false, 'error' => 'upload_failed', 'message' => 'Upload failed. Try a smaller PNG or SVG.'];
		}
		$tmp = (string) ($file['tmp_name'] ?? '');
		$size = (int) ($file['size'] ?? 0);
		if ($tmp === '' || !is_uploaded_file($tmp)) {
			return ['ok' => false, 'error' => 'upload_failed', 'message' => 'Upload did not arrive as a file.'];
		}
		if ($size <= 0 || $size > self::LOGO_MAX_BYTES) {
			return [
				'ok' => false,
				'error' => 'too_large',
				'message' => sprintf('File must be 1 byte to %d KB.', (int) (self::LOGO_MAX_BYTES / 1024)),
			];
		}
		$bytes = file_get_contents($tmp);
		if (!is_string($bytes) || $bytes === '') {
			return ['ok' => false, 'error' => 'empty', 'message' => 'The file was empty.'];
		}
		$checked = $this->validateCorporateLogoBytes($bytes);
		if (empty($checked['ok'])) {
			return $checked;
		}
		$name = trim(preg_replace('/\s+/', ' ', strip_tags($companyName)) ?? '');
		if (function_exists('mb_substr')) {
			$name = mb_substr($name, 0, 80);
		} else {
			$name = substr($name, 0, 80);
		}
		$dir = $this->corporateLogoDir();
		$path = $dir . '/corporate-logo.bin';
		if (file_put_contents($path, $bytes) === false) {
			return ['ok' => false, 'error' => 'store_failed', 'message' => 'Could not store the logo on this PBX.'];
		}
		@chmod($path, 0640);
		$meta = [
			'content_type' => $checked['content_type'],
			'width' => (int) ($checked['width'] ?? 0),
			'height' => (int) ($checked['height'] ?? 0),
			'dpi' => $checked['dpi'] ?? null,
			'bytes' => strlen($bytes),
			'company_name' => $name,
			'sha256' => hash('sha256', $bytes),
			'updated_at' => time(),
		];
		$this->setConfig('corporate_logo', $meta);
		return ['ok' => true, 'message' => 'Corporate logo saved. Licensed desks enrolled with this Hub will show it in the upper-left corner.', 'meta' => $meta];
	}

	public function clearCorporateLogo(): void {
		$path = $this->corporateLogoDir() . '/corporate-logo.bin';
		if (is_file($path)) {
			@unlink($path);
		}
		$this->setConfig('corporate_logo', []);
	}

	/**
	 * @return array{enabled:bool,content_type?:string,company_name?:string,width?:int,height?:int,dpi?:int|null,bytes?:int,updated_at?:int,sha256?:string}
	 */
	public function corporateLogoMeta(): array {
		$meta = $this->getConfig('corporate_logo');
		if (!is_array($meta) || empty($meta['content_type']) || empty($meta['sha256'])) {
			return ['enabled' => false];
		}
		$path = $this->corporateLogoDir() . '/corporate-logo.bin';
		if (!is_file($path)) {
			return ['enabled' => false];
		}
		$meta['enabled'] = true;
		return $meta;
	}

	/**
	 * Authenticated desk fetch. License tier is enforced by the Softphone, not here.
	 *
	 * @return array{ok:bool,http?:int,error?:string,enabled?:bool,content_type?:string,company_name?:string,width?:int,height?:int,dpi?:int|null,sha256?:string,logo_base64?:string}
	 */
	public function corporateLogoForDesk($extension, $secret): array {
		$ext = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $extension);
		$secret = (string) $secret;
		if ($ext === '' || $secret === '') {
			return ['ok' => false, 'http' => 400, 'error' => 'missing_credentials'];
		}
		$stored = (string) ($this->pjsipKeywords($ext)['secret'] ?? '');
		if ($stored === '' || !hash_equals($stored, $secret)) {
			return ['ok' => false, 'http' => 401, 'error' => 'auth_failed'];
		}
		$meta = $this->corporateLogoMeta();
		if (empty($meta['enabled'])) {
			return ['ok' => true, 'enabled' => false];
		}
		$path = $this->corporateLogoDir() . '/corporate-logo.bin';
		$bytes = file_get_contents($path);
		if (!is_string($bytes) || $bytes === '') {
			return ['ok' => true, 'enabled' => false];
		}
		return [
			'ok' => true,
			'enabled' => true,
			'content_type' => (string) $meta['content_type'],
			'company_name' => (string) ($meta['company_name'] ?? ''),
			'width' => (int) ($meta['width'] ?? 0),
			'height' => (int) ($meta['height'] ?? 0),
			'dpi' => $meta['dpi'] ?? null,
			'sha256' => (string) ($meta['sha256'] ?? ''),
			'logo_base64' => base64_encode($bytes),
		];
	}

	public function corporateLogoDataUri(): string {
		$meta = $this->corporateLogoMeta();
		if (empty($meta['enabled'])) {
			return '';
		}
		$bytes = file_get_contents($this->corporateLogoDir() . '/corporate-logo.bin');
		if (!is_string($bytes) || $bytes === '') {
			return '';
		}
		return 'data:' . $meta['content_type'] . ';base64,' . base64_encode($bytes);
	}

	/**
	 * @return array{ok:bool,error?:string,message?:string,content_type?:string,width?:int,height?:int,dpi?:int|null}
	 */
	public function validateCorporateLogoBytes(string $bytes): array {
		if (strlen($bytes) > self::LOGO_MAX_BYTES) {
			return [
				'ok' => false,
				'error' => 'too_large',
				'message' => sprintf('File must be %d KB or smaller.', (int) (self::LOGO_MAX_BYTES / 1024)),
			];
		}
		if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) {
			return $this->validateCorporatePng($bytes);
		}
		$head = ltrim(substr($bytes, 0, 512));
		if (stripos($head, '<svg') !== false || stripos($bytes, '<svg') !== false) {
			return $this->validateCorporateSvg($bytes);
		}
		return [
			'ok' => false,
			'error' => 'bad_type',
			'message' => 'Use a PNG (image/png) or SVG (image/svg+xml). JPG, GIF, and WebP are not accepted.',
		];
	}

	/**
	 * @return array{ok:bool,error?:string,message?:string,content_type?:string,width?:int,height?:int,dpi?:int|null}
	 */
	private function validateCorporatePng(string $bytes): array {
		$info = @getimagesizefromstring($bytes);
		$w = (int) ($info[0] ?? 0);
		$h = (int) ($info[1] ?? 0);
		if ($w < self::LOGO_MIN_PX || $h < self::LOGO_MIN_PX) {
			return [
				'ok' => false,
				'error' => 'too_small',
				'message' => sprintf('PNG must be at least %d×%d pixels. This file is %d×%d.', self::LOGO_MIN_PX, self::LOGO_MIN_PX, $w, $h),
			];
		}
		if ($w !== $h) {
			return [
				'ok' => false,
				'error' => 'not_square',
				'message' => sprintf('PNG must be square. This file is %d×%d.', $w, $h),
			];
		}
		$dpi = $this->pngDpi($bytes);
		if ($dpi === null) {
			return [
				'ok' => false,
				'error' => 'dpi_missing',
				'message' => sprintf('PNG has no DPI metadata. Export at %d DPI or higher (square, at least %d×%d).', self::LOGO_MIN_DPI, self::LOGO_MIN_PX, self::LOGO_MIN_PX),
			];
		}
		if ($dpi < self::LOGO_MIN_DPI) {
			return [
				'ok' => false,
				'error' => 'dpi_low',
				'message' => sprintf('PNG is %d DPI. Export at %d DPI or higher.', $dpi, self::LOGO_MIN_DPI),
			];
		}
		return [
			'ok' => true,
			'content_type' => 'image/png',
			'width' => $w,
			'height' => $h,
			'dpi' => $dpi,
		];
	}

	/**
	 * @return array{ok:bool,error?:string,message?:string,content_type?:string,width?:int,height?:int,dpi?:null}
	 */
	private function validateCorporateSvg(string $bytes): array {
		if (preg_match('/<script|javascript:|on[a-z]+\s*=|<foreignObject|<iframe/i', $bytes)) {
			return [
				'ok' => false,
				'error' => 'svg_unsafe',
				'message' => 'SVG must not contain scripts, event handlers, or embedded frames.',
			];
		}
		if (!preg_match('/<svg\b[^>]*>/i', $bytes, $m)) {
			return ['ok' => false, 'error' => 'bad_type', 'message' => 'File is not an SVG.'];
		}
		$tag = $m[0];
		$w = null;
		$h = null;
		if (preg_match('/\bwidth\s*=\s*["\']([\d.]+)/i', $tag, $mw)) {
			$w = (float) $mw[1];
		}
		if (preg_match('/\bheight\s*=\s*["\']([\d.]+)/i', $tag, $mh)) {
			$h = (float) $mh[1];
		}
		if (($w === null || $h === null) && preg_match('/\bviewBox\s*=\s*["\']\s*[\d.\-]+\s+[\d.\-]+\s+([\d.]+)\s+([\d.]+)/i', $tag, $vb)) {
			$w = (float) $vb[1];
			$h = (float) $vb[2];
		}
		if ($w === null || $h === null || $w <= 0 || $h <= 0) {
			return [
				'ok' => false,
				'error' => 'svg_size',
				'message' => 'SVG must declare a square viewBox or equal width and height.',
			];
		}
		if (abs($w - $h) > 0.5) {
			return [
				'ok' => false,
				'error' => 'not_square',
				'message' => 'SVG must be square (equal width and height, or a square viewBox).',
			];
		}
		return [
			'ok' => true,
			'content_type' => 'image/svg+xml',
			'width' => (int) round($w),
			'height' => (int) round($h),
			'dpi' => null,
		];
	}

	private function pngDpi(string $bytes): ?int {
		$off = 8;
		$len = strlen($bytes);
		while ($off + 12 <= $len) {
			$chunkLen = unpack('N', substr($bytes, $off, 4))[1];
			$type = substr($bytes, $off + 4, 4);
			if ($type === 'pHYs' && $chunkLen >= 9 && $off + 8 + 9 <= $len) {
				$data = substr($bytes, $off + 8, 9);
				$ppux = unpack('N', substr($data, 0, 4))[1];
				$unit = ord($data[8]);
				if ($unit === 1 && $ppux > 0) {
					return (int) round($ppux * 0.0254);
				}
				return null;
			}
			if ($type === 'IEND') {
				break;
			}
			$off += 12 + $chunkLen;
		}
		return null;
	}

	private function corporateLogoDir(): string {
		$spool = '/var/spool/asterisk';
		try {
			if (isset($this->FreePBX->Config)) {
				$configured = (string) $this->FreePBX->Config->get('ASTSPOOLDIR');
				if ($configured !== '') {
					$spool = $configured;
				}
			}
		} catch (\Throwable $e) {
			// default spool
		}
		$dir = rtrim($spool, '/') . '/xrflowsoftphone';
		if (!is_dir($dir)) {
			mkdir($dir, 0750, true);
		}
		return $dir;
	}
}
