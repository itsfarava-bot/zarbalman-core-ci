"""Validate a complete analysis report without public diagnostic details."""
import json
import sys
from pathlib import Path
data = json.loads(Path(sys.argv[1]).read_text())
assert data['totals']['errors'] == 0 and not data.get('errors'), 'Global analysis failure'
count = sum(len(file['messages']) for file in data['files'].values())
assert count == data['totals']['file_errors'], 'Incomplete diagnostic inventory'
print('PHPStan inventory:', count)
