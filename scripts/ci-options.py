"""Choose a bounded PHPUnit scope; main pushes always run the full suite."""
import json
import os
import pathlib
import re

root = pathlib.Path(__file__).resolve().parents[1]
config = json.loads((root / 'ci/run-options.json').read_text())
event_path = os.environ.get('GITHUB_EVENT_PATH')
event = json.loads(pathlib.Path(event_path).read_text()) if event_path else {}
if os.environ.get('GITHUB_EVENT_NAME') == 'workflow_dispatch':
    config = {**config, **event.get('inputs', {})}
if os.environ.get('GITHUB_EVENT_NAME') == 'push':
    config['scope'] = 'full'
assert config['scope'] in ('focused', 'full')
assert isinstance(config.get('filter', ''), str)
assert len(config.get('filter', '')) <= 1000
assert re.fullmatch(r'[A-Za-z0-9_|:./ ()\-]*', config.get('filter', ''))
if config['scope'] == 'focused':
    assert config['filter'], 'Focused mode requires an explicit test filter.'
if output := os.environ.get('GITHUB_OUTPUT'):
    with open(output, 'a') as stream:
        stream.write('scope=' + config['scope'] + '\n')
        stream.write('filter=' + config.get('filter', '') + '\n')
else:
    print(json.dumps(config))
