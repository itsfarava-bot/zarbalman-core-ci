"""The pinned WooCommerce packages stub supplies real Action Scheduler APIs."""
from pathlib import Path
import re
stub = Path('vendor/php-stubs/woocommerce-stubs/woocommerce-packages-stubs.php').read_text()
used = set()
for f in Path('plugin').rglob('*.php'):
    used.update(re.findall(r'\b(as_[a-z_]+)\s*\(', f.read_text()))
for function in sorted(used):
    assert re.search(r'function\s+' + function + r'\s*\(', stub), function
    print('Action Scheduler bundled stub:', function)
assert used, 'No Action Scheduler API verification performed'
print('Action Scheduler stub functions verified:', len(used))
