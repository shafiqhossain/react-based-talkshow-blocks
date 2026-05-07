module.exports = {
  extends: 'stylelint-config-standard',
  rules: {
    'at-rule-no-unknown': [
      true,
      {
        ignoreAtRules: [
          'define-mixin',
          'mixin',
        ],
      },
    ],
    'selector-class-pattern': null,
  },
  ignoreFiles: [
    'dist/**/*',
    'node_modules/**/*',
  ],
};
