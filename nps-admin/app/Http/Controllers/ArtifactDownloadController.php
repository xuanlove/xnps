<?php

namespace App\Http\Controllers;

use App\Models\UpgradeArtifact;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 制品下载端点（DESIGN.md §9.3 单台流程）：
 * nps 出方向经 /ext/upgrade url 模式拉取。
 * 鉴权：download_token（编排器生成的 URL 自带）+ sha256 在 nps 侧强校验。
 */
class ArtifactDownloadController extends Controller
{
    public function download(UpgradeArtifact $artifact, string $token): StreamedResponse|Response
    {
        if (! hash_equals($artifact->download_token, $token)) {
            return response()->json(['error' => 'invalid token'], 403);
        }
        if (! \Illuminate\Support\Facades\Storage::exists($artifact->filename)) {
            return response()->json(['error' => 'artifact file missing'], 404);
        }

        return response()->streamDownload(function () use ($artifact) {
            echo \Illuminate\Support\Facades\Storage::get($artifact->filename);
        }, 'nps', [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => $artifact->size_bytes,
            'X-Artifact-Sha256' => $artifact->sha256,
        ]);
    }
}
