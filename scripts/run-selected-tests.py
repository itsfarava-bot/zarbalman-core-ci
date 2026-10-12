"""Run exactly the chosen PHPUnit scope; preserve native results and exit code."""
import os
import pathlib
import subprocess

root = pathlib.Path(__file__).resolve().parents[1]
command = [str(root / 'vendor/bin/phpunit'), '--configuration=phpunit-task4.xml', '--log-junit=evidence/candidate-junit.xml']
if scope := os.environ.get('ZBM_PHPUNIT_FILTER'):
    command += ['--filter', scope]
(root / 'evidence').mkdir(exist_ok=True)
(root / 'evidence/http-matrix-run-start').touch()
result = subprocess.run(command, cwd=root, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
(root / 'evidence/candidate-phpunit.txt').write_text(result.stdout)
print(result.stdout, end='')
raise SystemExit(result.returncode)
