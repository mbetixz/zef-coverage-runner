from http.server import BaseHTTPRequestHandler, HTTPServer
import json,sys
mode=sys.argv[1];port=int(sys.argv[2])
class H(BaseHTTPRequestHandler):
  protocol_version='HTTP/1.0'
  def do_POST(self):
    n=int(self.headers.get('Content-Length','0')); data=self.rfile.read(n)
    try: json.loads(data)
    except Exception: self.send_response(400); self.send_header('Content-Length','2'); self.end_headers(); self.wfile.write(b'{}'); return
    code={'ok':200,'400':400,'500':500}[mode]
    self.send_response(code); self.send_header('Content-Length','2'); self.end_headers(); self.wfile.write(b'{}')
  def log_message(self,*a): pass
HTTPServer(('127.0.0.1',port),H).serve_forever()
