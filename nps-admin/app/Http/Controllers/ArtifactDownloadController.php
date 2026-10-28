<?php

namespace App\Http\Controllers;

use App\Models\UpgradeArtifact;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 制品下载端点（DESIGN.md §9.3 单台流程）：
 * nps 出方向经 /ext/upgrade url 模式拉取。
 * 鉴权：download_token（编排器生成的 URL 自带）+ sha256 在 nps 侧强校验。
 * token 带过期时间（AUDIT L1），文件流式输出避免整读内存。
 */
class ArtifactDownloadController extends Controller
{
    public function download(UpgradeArtifact $artifact, string $token): StreamedResponse|JsonResponse
    {
        if ($artifact->download_token === '' || ! hash_equals($artifact->download_token, $token)) {
            return response()->json(['error' => 'invalid token'], 403);
        }
        if ($artifact->download_token_expires_at !== null && $artifact->download_token_expires_at->isPast()) {
            return response()->json(['error' => 'download token expired'], 410);
        }
        if (! Storage::exists($artifact->filename)) {
            return response()->json(['error' => 'artifact file missing'], 404);
        }

        $stream = Storage::readStream($artifact->filename);
        if (! is_resource($stream)) {
            return response()->json(['error' => 'artifact file unreadable'], 404);
        }

        return response()->streamDownload(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, 'nps', [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => $artifact->size_bytes,
            'X-Artifact-Sha256' => $artifact->sha256,
            'Cache-Control' => 'no-store',
        ]);
    }
}
