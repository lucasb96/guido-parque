<?php
declare(strict_types=1);

/**
 * Cliente mínimo da Google Drive API v3 (somente leitura, pastas públicas, chave de API).
 */
final class Drive
{
    private const API = 'https://www.googleapis.com/drive/v3';

    public function __construct(private string $apiKey)
    {
    }

    /**
     * Lista recursivamente uma pasta.
     * @return array<int, array> arquivos com folder_id e folder_path
     */
    public function listRecursive(string $folderId, string $path = '', int $depth = 0): array
    {
        if ($depth > 6) return [];
        $out = [];
        $pageToken = null;
        do {
            $params = [
                'q' => "'$folderId' in parents and trashed = false",
                'fields' => 'nextPageToken, files(id, name, mimeType, modifiedTime, size, md5Checksum)',
                'pageSize' => 1000,
                'key' => $this->apiKey,
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ];
            if ($pageToken) $params['pageToken'] = $pageToken;
            $res = $this->getJson(self::API . '/files?' . http_build_query($params));

            foreach ($res['files'] ?? [] as $f) {
                $f['folder_id'] = $folderId;
                $f['folder_path'] = $path;
                if ($f['mimeType'] === 'application/vnd.google-apps.folder') {
                    $out[] = $f;
                    $sub = $path === '' ? $f['name'] : "$path / {$f['name']}";
                    array_push($out, ...$this->listRecursive($f['id'], $sub, $depth + 1));
                } else {
                    $out[] = $f;
                }
            }
            $pageToken = $res['nextPageToken'] ?? null;
        } while ($pageToken);

        return $out;
    }

    /** Nome de uma pasta (para montar o caminho raiz). */
    public function folderName(string $folderId): string
    {
        $res = $this->getJson(self::API . "/files/$folderId?" . http_build_query(['fields' => 'name', 'key' => $this->apiKey, 'supportsAllDrives' => 'true']));
        return $res['name'] ?? $folderId;
    }

    /** Baixa um arquivo binário para um caminho local. */
    public function download(string $fileId, string $dest): void
    {
        $url = self::API . "/files/$fileId?" . http_build_query(['alt' => 'media', 'key' => $this->apiKey, 'supportsAllDrives' => 'true']);
        try {
            $this->fetchToFile($url, $dest);
        } catch (RuntimeException $e) {
            // A API às vezes recusa downloads anônimos (limite de cota/403) ou a rede oscila:
            // tenta pelo link público de download do Drive.
            sleep(2);
            $this->fetchToFile('https://drive.usercontent.google.com/download?' . http_build_query([
                'id' => $fileId, 'export' => 'download', 'confirm' => 't',
            ]), $dest);
            // Se o Google devolveu uma página HTML (aviso/erro) em vez do arquivo, falha
            $head = (string) file_get_contents($dest, false, null, 0, 512);
            if (stripos($head, '<!DOCTYPE html') !== false || stripos($head, '<html') !== false) {
                @unlink($dest);
                throw new RuntimeException('Download recusado pelo Google: ' . $e->getMessage());
            }
        }
    }

    /** Exporta um arquivo nativo do Google (Docs/Sheets) como texto/CSV. */
    public function export(string $fileId, string $mime): string
    {
        $url = self::API . "/files/$fileId/export?" . http_build_query(['mimeType' => $mime, 'key' => $this->apiKey]);
        return $this->fetch($url);
    }

    /* ------------------------------------------------------------------ */

    private function getJson(string $url): array
    {
        $body = $this->fetch($url);
        $data = json_decode($body, true);
        if (!is_array($data)) throw new RuntimeException('Resposta inválida da API do Google.');
        if (isset($data['error'])) {
            throw new RuntimeException('Google Drive API: ' . ($data['error']['message'] ?? 'erro desconhecido'));
        }
        return $data;
    }

    private function fetch(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'CentralDuvidas/1.0',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) throw new RuntimeException("Falha de rede: $err");
        if ($code >= 400) {
            $msg = json_decode($body, true)['error']['message'] ?? "HTTP $code";
            throw new RuntimeException("Google Drive API: $msg");
        }
        return $body;
    }

    private function fetchToFile(string $url, string $dest): void
    {
        $fp = fopen($dest, 'wb');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_USERAGENT => 'CentralDuvidas/1.0',
        ]);
        $ok = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $code >= 400) {
            @unlink($dest);
            throw new RuntimeException($ok ? "Download falhou (HTTP $code)" : "Download falhou: $err");
        }
    }
}
