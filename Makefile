# Makefile for UUWAF

tar:
	# Usage
	@echo "Usage: rel=<version>; rel is set to ${rel}"
	@test ! -z ${rel}
	# Empty that target dir first
	rm -rf ../tarballs/uuwaf-${rel}
	# takes rel= as argument
	mkdir -p ../tarballs/uuwaf-${rel}
	# And any existing tarballs
	rm -rf ../tarballs/uuwaf_${rel}.orig.tar.gz
	# Copy new content in
	cp -rf * ../tarballs/uuwaf-$(rel)
	# Remove svn files, debian dir and this Makefile, since it
	# is very debian specific right now
	rm -rf `find ../tarballs/uuwaf-$(rel) -type d -name ".svn"`
	rm -rf `find ../tarballs/uuwaf-$(rel) -name "*~"`
	rm -rf ../tarballs/uuwaf-${rel}/Makefile
	rm -rf ../tarballs/uuwaf-$(rel)/debian
	# actually perform the gzip
	cd ../tarballs && tar cfz uuwaf_$(rel).orig.tar.gz uuwaf-$(rel)
	rm -rf ../tarballs/uuwaf-$(rel)
	@echo "Targz build in ../tarballs"

clean:
	find . \( -name "#*#" -or -name ".#*" -or -name "*~" -or -name ".*~" \) -exec rm -rfv {} \;
	rm -fv *.cache
	rm -rf debian/uuwaf
	rm -rf debian/files

# Make development documentation, you will need phpdoc installed
devdoc:
	mkdir -p ../phpdoc/
	phpdoc -d html -d include -t ../phpdoc/ -dn UUWAF --title "UUWAF Development Documentation"

# These defaults are for debian
# default place for configuration
etc=/etc/
# default place for install will be this path followed by /share/uuwaf
prefix=/usr
# default place for html content
www=/www
# default name for webuser
webuser=www-data

fedora-config:
	etc=/etc
	prefix=/usr
	www=/www
	webuser=/www-data

fedora-install: fedora-config install

debian-install: install

install: uuwaf-core

uuwaf-core:
	# Make main directory and copy in contents
	mkdir -p ${prefix}/share/uuwaf
	cp -rf include ${prefix}/share/uuwaf
	chown -R ${webuser}:root ${prefix}/share/uuwaf/
	chmod -R o-rwx ${prefix}/share/uuwaf/
	mkdir -p ${prefix}/share/doc/uuwaf


uuwaf-doc:
	mkdir -p $(prefix)/share/doc/uuwaf-doc
	mkdir -p $(prefix)/share/doc/uuwaf-doc/api/
	phpdoc -d include -t $(prefix)/share/doc/uuwaf-doc/api/ -dn UUWAF --title "UUWAF Development Documentation"


build_debs:
	dpkg-buildpackage -rfakeroot
