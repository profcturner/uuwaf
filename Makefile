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
	phpdoc -d html -d include -t ../phpdoc/ --title "UUWAF Development Documentation"

#
# Currently contains commands for making with Debian
#

debetc=/etc
debprefix=/usr
debwww=/var/www

debs: deb-uuwaf

deb-uuwaf: deb-uuwaf-etc
	# Make main directory and copy in contents
	mkdir -p ${debprefix}/share/uuwaf
	cp -rf html ${debprefix}/share/uuwaf
	cp -rf include ${debprefix}/share/uuwaf
	cp -rf cron ${debprefix}/share/uuwaf
	cp -rf templates ${debprefix}/share/uuwaf
	mkdir ${debprefix}/share/uuwaf/templates_c
	mkdir ${debprefix}/share/uuwaf/templates_cache
	chown -R www-data:root ${debprefix}/share/uuwaf/
	chmod -R o-rwx ${debprefix}/share/uuwaf/
	# Nuke license file for xinha, it is contained in debian/copyright
	# and disturbs lintian
	rm ${debprefix}/share/uuwaf/html/jsincludes/htmlarea/license.txt
	# Make documentation directory
	mkdir -p ${debprefix}/share/doc/uuwaf
	cp -rf sql_patch ${debprefix}/share/doc/uuwaf


deb-uuwaf-etc: 
	mkdir -p ${debetc}/uuwaf
	cp include/config.php.debian ${debetc}/uuwaf/config.php
	cp etc/apache2.conf ${debetc}/uuwaf/apache2.conf


deb-uuwaf-doc:
	mkdir -p $(debprefix)/share/doc/uuwaf-doc
	cp -rf docs ${debprefix}/share/doc/uuwaf-doc

build_debs:
	dpkg-buildpackage -rfakeroot
