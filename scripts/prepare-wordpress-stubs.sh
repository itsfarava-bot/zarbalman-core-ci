#!/usr/bin/env bash
set -euo pipefail
export COMPOSER_HOME="${RUNNER_TEMP:-/tmp}/zbm-composer-isolated"
export COMPOSER_AUTH='{}'
case "${ZBM_WP_VERSION:-}" in
  7.1.2)
    composer show php-stubs/wordpress-stubs --format=json > evidence/wp-stub-provenance.json
    php -r '$p=json_decode(file_get_contents("evidence/wp-stub-provenance.json"),true); if(!in_array("v7.1.2",$p["versions"],true)){exit(1);}'
    echo 'Exact published WordPress stubs v7.1.2'
    ;;
  7.0.3)
    mkdir -p .runtime/stub-source
    wp core download --path=.runtime/stub-source --version=7.0.3 --locale=en_US
    test "$(wp core version --path=.runtime/stub-source)" = 7.0.3
    export ZBM_STUB_SOURCE="$PWD/.runtime/stub-source"
    vendor/bin/generate-stubs --force --finder=ci/wordpress-finder.php --functions --classes --interfaces --traits --out=.runtime/wordpress-stubs-7.0.3.php
    cat >> .runtime/wordpress-stubs-7.0.3.php <<'GLOBALS'

namespace {
/** @var wpdb */
$wpdb = null;
}
GLOBALS
    php -l .runtime/wordpress-stubs-7.0.3.php
    php ci/build-stub-autoloader.php .runtime/wordpress-stubs-7.0.3.php .runtime/wp-stub-bootstrap
sed -i "s#vendor/php-stubs/wordpress-stubs/wordpress-stubs.php#.runtime/wordpress-stubs-7.0.3.php#" phpstan.neon phpstan-raw.neon
    python3 - <<'PROVENANCE'
import hashlib,json
from pathlib import Path
p=Path('.runtime/wordpress-stubs-7.0.3.php')
d={'wordpress':'7.0.3','generator':'0.9.0','finder':'ci/wordpress-finder.php','visitor':'none; default source-preserving generator', 'bootstrap':'lazy class/interface/trait autoloader, full unmodified generated source is scanned','sha256':hashlib.sha256(p.read_bytes()).hexdigest(),'method':'Generated from exact WordPress 7.0.3 core, not a published 7.0.3 package; installed bootstrap replaced only inside disposable analysis job; exact generated source scanned'}
Path('evidence/wp-stub-provenance.json').write_text(json.dumps(d,indent=2))
print(d)
PROVENANCE
    ;;
  *) echo 'Unsupported requested WordPress stub version'; exit 1;;
esac
