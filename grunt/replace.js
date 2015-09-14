module.exports = {
      readme_txt: {
        src: [ 'readme.txt' ],
        overwrite: true,
        replacements: [{
          from: /Stable tag: (.*)/,
          to: "Stable tag: <%= pkg.version %>"
        }]
      },
      main_php: {
        src: [ '<%= pkg.pot.src %>' ],
        overwrite: true,
        replacements: [{
          from: /define(.*)_VER'.*/,
          to: "define( '<%= pkg.constant.ver %>' , '<%= pkg.version %>' );"
        },{
          from: / Version:\s*(.*)/,
          to: " Version: <%= pkg.version %>"
        }]
      },
       all_php: {
        src: ['**/*.php',
      '!node_modules/**',
      '!grunt/**',
      '!build/**'],
      overwrite: true,
        replacements: [{
          from: /EDD_PN_TRANSLATE/,
          to: "EDD_<%= pkg.constant.cst %>_TRANSLATE"
        },{
            from: /edd-plugin-name-translations/,
          to: "<%= pkg.pot.textdomain %>"
        },{
            from: /edd-plugin-source/,
          to: "<%= pkg.pot.tdsource %>"
        }]
      }
    };