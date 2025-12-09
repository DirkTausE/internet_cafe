#!/usr/bin/env python3
# greeter.py - einfaches GTK-Startfenster (siehe vorheriges Beispiel)
import gi, socket, requests
gi.require_version('Gtk', '3.0')
from gi.repository import Gtk
HOSTNAME = socket.gethostname()
SERVER_URL = "http://server.local/api.php?q="
API_KEY = "CHANGE_ME_API_KEY"

class GreeterWindow(Gtk.Window):
    def __init__(self):
        super().__init__(title="Internetcafe Greeter")
        self.set_default_size(360,180)
        box = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=8, margin=12)
        self.add(box)
        box.pack_start(Gtk.Label(label=f"PC: {HOSTNAME}"), False, False, 0)
        btns = Gtk.Box(spacing=6)
        box.pack_start(btns, False, False, 0)
        b1 = Gtk.Button(label="Normal starten")
        b1.connect("clicked", self.on_start, False)
        btns.pack_start(b1, True, True, 0)
        b2 = Gtk.Button(label="Diako starten")
        b2.connect("clicked", self.on_start, True)
        btns.pack_start(b2, True, True, 0)
        admin = Gtk.Button(label="Admin")
        admin.connect("clicked", self.on_admin)
        box.pack_start(admin, False, False, 0)

    def on_start(self, button, diako):
        try:
            r = requests.post(SERVER_URL + "session/start", json={'host': HOSTNAME, 'is_diako': bool(diako)}, headers={'X-API-KEY': API_KEY}, timeout=5)
            if r.ok:
                dlg = Gtk.MessageDialog(self, 0, Gtk.MessageType.INFO, Gtk.ButtonsType.OK, "Sitzung gestartet")
                dlg.run(); dlg.destroy()
                Gtk.main_quit()
            else:
                dlg = Gtk.MessageDialog(self, 0, Gtk.MessageType.ERROR, Gtk.ButtonsType.OK, "Server Fehler")
                dlg.run(); dlg.destroy()
        except Exception as e:
            dlg = Gtk.MessageDialog(self, 0, Gtk.MessageType.ERROR, Gtk.ButtonsType.OK, f"Fehler: {e}")
            dlg.run(); dlg.destroy()

    def on_admin(self, button):
        # Lokale Adminaktion: prompt
        dlg = Gtk.MessageDialog(self, 0, Gtk.MessageType.INFO, Gtk.ButtonsType.OK, "Admin: siehe Webfrontend")
        dlg.run(); dlg.destroy()

def main():
    win = GreeterWindow()
    win.connect("destroy", Gtk.main_quit)
    win.show_all()
    Gtk.main()

if __name__ == '__main__':
    main()
