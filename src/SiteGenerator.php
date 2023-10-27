<?php

namespace Pluckypenguinphil\WslSiteManager;

use Exception;

class SiteGenerator
{
    public function generate(
        string $domain,
        string $directory,
        ?string $database = null,
        ?string $phpVersion = '7.4'
    ): void {
        $this->generateSSLCertificate($domain);
        $this->fixPermissionsForProject($directory);

        $this->saveNginxConfig(
            $domain,
            $this->generateNginxSiteConfig(
                $domain,
                $directory,
                $phpVersion
            )
        )
            ->enableNginxSite($domain);

        if (!empty($database)) {
            $this->createDatabase($database);
        }
    }

    /**
     * @param  string  $domain
     * @param  string  $directory
     * @param  string  $phpVersion
     *
     * @return string
     */
    private function generateNginxSiteConfig(string $domain, string $directory, string $phpVersion = '7.4'): string
    {
        return str_replace(
            ['__SERVER_NAME__', '__ROOT__', '__PHP_VERSION__'],
            [$domain, $directory, $phpVersion],
            file_get_contents(dirname(__DIR__).'/stubs/nginx.stub')
        );
    }

    /**
     * @param  string  $domain
     * @param  string  $nginxConfiguration
     *
     * @return \Pluckypenguinphil\WslSiteManager\SiteGenerator
     * @throws \Exception
     */
    private function saveNginxConfig(string $domain, string $nginxConfiguration): SiteGenerator
    {
        $filePath = "/etc/nginx/sites-available/$domain";
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        if (false === file_put_contents($filePath, $nginxConfiguration)) {
            throw new Exception("Failed to create nginx site.");
        }
        return $this;
    }

    /**
     * @param  string  $domain
     *
     * @return bool
     */
    private function enableNginxSite(string $domain): bool
    {
        exec("ln -s /etc/nginx/sites-available/$domain /etc/nginx/sites-enabled/$domain", $output, $resultCode);
        if ($resultCode != 0) {
            return false;
        }
        $this->reloadNginx();
        return true;
    }

    /**
     * @param  string  $domain
     *
     * @return void
     */
    private function generateSSLCertificate(string $domain): void
    {
        if (!file_exists("/usr/share/ca-certificates/nginx/$domain")) {
            exec(
                "mkcert -cert-file /usr/share/ca-certificates/nginx/$domain.pem -key-file /usr/share/ca-certificates/nginx/$domain-key.pem $domain"
            );
        }
    }

    /**
     * @param  string  $database
     *
     * @return void
     */
    private function createDatabase(string $database): void
    {
        $user = getenv('DB_USERNAME');
        $password = getenv('DB_PASSWORD');
        if (!empty($password)) {
            $password = '-p '.$password;
        }
        exec("mysql -u $user $password -e CREATE SCHEMA $database");
    }

    private function fixPermissionsForProject($directory): void
    {
        exec("chown -R :www-data $directory");
        exec("chmod -R g+w $directory");
    }

    public function destroy(string $domain): void
    {
        $this->removeNginxSite($domain);
        $this->removeSSLCertificates($domain);
        $this->reloadNginx();
    }

    private function removeNginxSite(string $domain): void
    {
        unlink("/etc/nginx/sites-enabled/$domain");
        unlink("/etc/nginx/sites-available/$domain");
    }

    private function removeSSLCertificates(string $domain): void
    {
        unlink("/usr/share/ca-certificates/nginx/$domain.pem");
        unlink("/usr/share/ca-certificates/nginx/$domain-key.pem");
    }

    private function reloadNginx(): void
    {
        exec('systemctl reload nginx.service');
    }
}
