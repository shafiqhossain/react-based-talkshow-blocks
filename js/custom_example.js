(function($) {
    Drupal.behaviors.custom_example = {
        attach: function() {

        }
    };

})(jQuery);

(function($) {
  $('input[name="verification_code_1"]').focus();
  $('.verification-codes-wrapper input').keyup(function(){
    if (this.value.length === this.maxLength) {
      let next = $(this).data('next');
      if (next == '7') {
        $('.form-submit').focus();
      }
      else {
        $('input[name="verification_code_'+next+'"]').focus();
      }
    }
  });
})(jQuery);
