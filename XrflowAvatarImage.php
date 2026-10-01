<?php
/**
 * Turn User Manager photo blobs into small data URLs.
 *
 * No FreePBX bootstrap. The desktop app paints these in BLF, favorites,
 * and directory browse. Gravatar bytes already stored by Contact Manager
 * are returned as-is; this file does not call gravatar.com.
 *
 * Copyright (C) 2026 XRFlow
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
namespace FreePBX\modules;

class XrflowAvatarImage {
	public const MAX_EDGE = 96;
	/** Skip blobs larger than this before GD. */
	public const MAX_INPUT_BYTES = 1500000;
	public const MAX_PASSTHROUGH_BYTES = 120000;
	public const MAX_OUTPUT_BYTES = 180000;

	/**
	 * @param mixed $blob
	 * @return string|null data:image/...;base64,...
	 */
	public static function dataUrl($blob, $format = '') {
		if (is_resource($blob)) {
			$blob = stream_get_contents($blob);
		}
		if (!is_string($blob) || strlen($blob) < 32 || strlen($blob) > self::MAX_INPUT_BYTES) {
			return null;
		}
		$resized = self::resizePng($blob);
		if (is_string($resized) && strlen($resized) >= 32 && strlen($resized) <= self::MAX_OUTPUT_BYTES) {
			return 'data:image/png;base64,' . base64_encode($resized);
		}
		if (strlen($blob) > self::MAX_PASSTHROUGH_BYTES) {
			return null;
		}
		$mime = self::mime($format, $blob);
		if ($mime === null) {
			return null;
		}
		return 'data:' . $mime . ';base64,' . base64_encode($blob);
	}

	/**
	 * @param mixed $raw
	 * @return list<string>
	 */
	public static function assignedExtensions($raw) {
		$list = [];
		if (is_array($raw)) {
			$list = $raw;
		} else {
			$s = trim((string) $raw);
			if ($s === '') {
				return [];
			}
			$decoded = json_decode($s, true);
			if (is_array($decoded)) {
				$list = $decoded;
			} elseif (preg_match('/^\d{2,8}$/', $s)) {
				return [$s];
			} else {
				return [];
			}
		}
		$out = [];
		foreach ($list as $item) {
			if (is_array($item)) {
				$item = $item['extension'] ?? $item['ext'] ?? '';
			}
			$ext = trim((string) $item);
			if ($ext === '' || strcasecmp($ext, 'none') === 0) {
				continue;
			}
			if (!preg_match('/^[0-9A-Za-z_-]{1,20}$/', $ext)) {
				continue;
			}
			$out[] = $ext;
		}
		return array_values(array_unique($out));
	}

	/**
	 * One directory row. Null when there is no extension, and neither a photo nor an email.
	 *
	 * @param array<string,mixed> $user
	 * @param mixed $assignedRaw
	 * @return array{extension:string,extensions:list<string>,email:string,name:string,avatar?:string}|null
	 */
	public static function userPayload(array $user, $avatarDataUrl, $assignedRaw) {
		$exts = [];
		$def = trim((string) ($user['default_extension'] ?? ''));
		if ($def !== '' && strcasecmp($def, 'none') !== 0) {
			$exts[] = $def;
		}
		$username = trim((string) ($user['username'] ?? ''));
		if ($username !== '' && preg_match('/^\d{2,8}$/', $username)) {
			$exts[] = $username;
		}
		foreach (self::assignedExtensions($assignedRaw) as $assigned) {
			$exts[] = $assigned;
		}
		$exts = array_values(array_unique($exts));
		$primary = $exts[0] ?? '';
		if ($primary === '') {
			return null;
		}
		$email = trim((string) ($user['email'] ?? ''));
		if ($email === '' || strpos($email, '@') === false) {
			$email = '';
		}
		$fname = trim((string) ($user['fname'] ?? ''));
		$lname = trim((string) ($user['lname'] ?? ''));
		$name = trim($fname . ' ' . $lname);
		if ($name === '') {
			$name = trim((string) ($user['displayname'] ?? ''));
		}
		$avatar = is_string($avatarDataUrl) ? trim($avatarDataUrl) : '';
		if ($avatar !== '' && !preg_match('#^data:image/[a-z0-9.+-]+;base64,[A-Za-z0-9+/=\s]+$#i', $avatar)) {
			$avatar = '';
		}
		if ($avatar === '' && $email === '') {
			return null;
		}
		$row = [
			'extension' => $primary,
			'extensions' => $exts,
			'email' => self::utf8($email),
			'name' => self::utf8($name),
		];
		if ($avatar !== '') {
			$row['avatar'] = preg_replace('/\s+/', '', $avatar);
		}
		return $row;
	}

	/**
	 * @param string $blob
	 * @return string|null PNG bytes
	 */
	private static function resizePng($blob) {
		if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
			return null;
		}
		$im = @imagecreatefromstring($blob);
		if ($im === false) {
			return null;
		}
		$w = imagesx($im);
		$h = imagesy($im);
		if ($w < 1 || $h < 1) {
			imagedestroy($im);
			return null;
		}
		$scale = min(self::MAX_EDGE / $w, self::MAX_EDGE / $h, 1);
		$nw = max(1, (int) round($w * $scale));
		$nh = max(1, (int) round($h * $scale));
		$dst = imagecreatetruecolor($nw, $nh);
		if ($dst === false) {
			imagedestroy($im);
			return null;
		}
		imagealphablending($dst, false);
		imagesavealpha($dst, true);
		$transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
		imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
		imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
		ob_start();
		$wrote = imagepng($dst);
		$png = ob_get_clean();
		imagedestroy($im);
		imagedestroy($dst);
		if ($wrote !== true || !is_string($png) || strlen($png) < 32) {
			return null;
		}
		return $png;
	}

	/**
	 * @param string $blob
	 */
	private static function mime($format, $blob) {
		$f = strtolower(trim((string) $format));
		$f = preg_replace('#^image/#', '', $f);
		$map = [
			'png' => 'image/png',
			'jpeg' => 'image/jpeg',
			'jpg' => 'image/jpeg',
			'gif' => 'image/gif',
			'webp' => 'image/webp',
		];
		if (isset($map[$f])) {
			return $map[$f];
		}
		if (strncmp($blob, "\x89PNG", 4) === 0) {
			return 'image/png';
		}
		if (strncmp($blob, "\xFF\xD8\xFF", 3) === 0) {
			return 'image/jpeg';
		}
		if (strncmp($blob, 'GIF8', 4) === 0) {
			return 'image/gif';
		}
		if (strlen($blob) >= 12 && substr($blob, 0, 4) === 'RIFF' && substr($blob, 8, 4) === 'WEBP') {
			return 'image/webp';
		}
		return null;
	}

	private static function utf8($value) {
		$s = (string) $value;
		if ($s === '') {
			return '';
		}
		if (function_exists('iconv')) {
			$clean = iconv('UTF-8', 'UTF-8//IGNORE', $s);
			if (is_string($clean)) {
				return $clean;
			}
		}
		return $s;
	}
}
