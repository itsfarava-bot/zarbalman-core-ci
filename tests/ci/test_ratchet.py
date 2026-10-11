import importlib.util
import json
import pathlib
import subprocess
import sys
import tempfile
import unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('ratchet',ROOT/'scripts/ratchet-findings.py')
r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
class RatchetTest(unittest.TestCase):
    def setUp(self):
        self.row=dict(file='plugin/src/file.php',line=12,rule='WordPress.Security.Example',message='unsafe',type='ERROR')
    def test_unchanged_and_resolved_are_accepted(self):
        self.assertFalse(r.compare([self.row],[self.row]));self.assertFalse(r.compare([],[self.row]))
    def test_fingerprint_baseline_keeps_every_field_and_multiplicity(self):
        baseline=[r.fingerprint(self.row)]
        self.assertFalse(r.compare([self.row],baseline))
        self.assertFalse(r.compare([],baseline))
        for patch in [dict(file='plugin/src/new.php'),dict(line=13),dict(rule='New.Rule'),dict(message='changed'),dict(type='WARNING')]:
            self.assertEqual(1,sum(r.compare([dict(self.row,**patch)],baseline).values()))
        self.assertEqual(1,sum(r.compare([self.row,self.row],baseline).values()))
    def test_malformed_fingerprint_is_rejected(self):
        for value in ['broken','g'*64,'0'*63]:
            with self.assertRaises(AssertionError):r.counter([value])
    def test_added_changed_moved_and_duplicate_are_blocked(self):
        for patch in [dict(file='plugin/src/new.php'),dict(line=13),dict(rule='New.Rule'),dict(message='new unsafe'),dict(type='WARNING')]:
            self.assertEqual(1,sum(r.compare([dict(self.row,**patch)],[self.row]).values()))
        self.assertEqual(1,sum(r.compare([self.row,self.row],[self.row]).values()))
    def test_global_static_failure_is_infrastructure_failure(self):
        with tempfile.TemporaryDirectory() as d:
            p=pathlib.Path(d)/'report.json';p.write_text(json.dumps(dict(totals=dict(errors=1,file_errors=0),errors=['broken'],files={})))
            with self.assertRaises(AssertionError):r.findings('phpstan',p)
    def test_malformed_or_empty_phpcs_report_is_failure(self):
        with tempfile.TemporaryDirectory() as d:
            p=pathlib.Path(d)/'report.json';p.write_text('{"files":{},"totals":{"errors":0,"warnings":0}}')
            with self.assertRaises(AssertionError):r.findings('phpcs',p)
    def test_unknown_security_source_remains_blocking(self):
        with tempfile.TemporaryDirectory() as d:
            p=pathlib.Path(d)/'report.json';p.write_text(json.dumps(dict(totals=dict(errors=1,warnings=0),files={'plugin/src/file.php':dict(messages=[dict(line=1,source='Unknown.Security.Rule',message='bad',type='ERROR')])})))
            self.assertEqual('Unknown.Security.Rule',r.findings('phpcs',p)[0]['rule'])
    def test_malformed_style_diagnostic_cannot_bypass_validation(self):
        style=next(iter(json.loads((ROOT/'ci/phpcs-style-sniffs.json').read_text())))
        with tempfile.TemporaryDirectory() as d:
            p=pathlib.Path(d)/'report.json';p.write_text(json.dumps(dict(totals=dict(errors=1,warnings=0),files={'plugin/src/file.php':dict(messages=[dict(source=style)])})))
            with self.assertRaises((AssertionError,ValueError,KeyError)):r.findings('phpcs',p)
    def test_invalid_line_message_and_severity_are_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            p=pathlib.Path(d)/'report.json'
            for patch in [dict(line=True),dict(message=99),dict(type='BANANA')]:
                m=dict(line=1,source='Unknown.Security.Rule',message='bad',type='ERROR',**{})
                m.update(patch)
                p.write_text(json.dumps(dict(totals=dict(errors=1,warnings=0),files={'plugin/src/file.php':dict(messages=[m])})))
                with self.assertRaises((AssertionError,ValueError,KeyError)):r.findings('phpcs',p)
    def test_baseline_growth_without_original_integrity_is_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            baseline=json.loads((ROOT/'ci/ratchet/phpcs.json').read_text())
            baseline['findings'].append(self.row)
            b=pathlib.Path(d)/'baseline.json';b.write_text(json.dumps(baseline))
            p=pathlib.Path(d)/'report.json'
            p.write_text(json.dumps(dict(totals=dict(errors=1,warnings=0),files={self.row['file']:dict(messages=[dict(line=self.row['line'],source=self.row['rule'],message=self.row['message'],type=self.row['type'])])})))
            actual=subprocess.run([sys.executable,str(ROOT/'scripts/ratchet-findings.py'),'phpcs',str(p),'--baseline',str(b)],capture_output=True,text=True)
            self.assertNotEqual(0,actual.returncode,actual.stdout)
if __name__=='__main__':unittest.main()
