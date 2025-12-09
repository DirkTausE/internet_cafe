#!/usr/bin/env python3
# clients/greeter_minimal.py - Zeigt nur Hintergrund (GTK3)
import gi, sys, os
gi.require_version('Gtk','3.0')
from gi.repository import Gtk, GdkPixbuf

DEFAULT_BG = '/usr/share/backgrounds/xfce/xfce-blue.jpg'
img = sys.argv[1] if len(sys.argv) > 1 else DEFAULT_BG

win = Gtk.Window()
win.set_decorated(False)
win.fullscreen()
if os.path.exists(img):
    pixbuf = GdkPixbuf.Pixbuf.new_from_file_at_scale(img, 1920, 1080, False)
    win.add(Gtk.Image.new_from_pixbuf(pixbuf))
else:
    win.add(Gtk.Label(label="Hintergrundbild nicht gefunden"))
win.connect("destroy", Gtk.main_quit)
win.show_all()
Gtk.main()
