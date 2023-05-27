require(['core/first', 'jquery', 'jqueryui', 'core/ajax'], function(core, $, bootstrap, ajax) {


  $(document).ready(function() {
    $('.usa-accordion__button').on('keypress', function(e) {
      if($(this).attr('aria-expanded') === 'false') {
        $(this).removeClass('collapsed');
        $(this).attr('aria-expanded', 'true');
        $(this).parent().next().addClass('show');
      }
      else {
        $(this).attr('aria-expanded', 'false');
        $(this).addClass('collapsed');
        $(this).parent().next().removeClass('show');
      }
    });
    $('.usa-accordion__button').on('click', function() {
      if($(this).attr('aria-expanded') === 'false') {
        $(this).removeClass('collapsed');
        $(this).attr('aria-expanded', 'true');
        $(this).parent().next().addClass('show');
      }
      else {
        $(this).attr('aria-expanded', 'false');
        $(this).addClass('collapsed');
        $(this).parent().next().removeClass('show');
      }
    });


    // Sticky headers
    window.onscroll = function() {myFunction()};
    var elem = document.querySelector('#star-dashboard--header');
    var clone = elem.cloneNode(true);
    clone.classList.add("cloned--star-dashboard--header");

    // Header position from top of page
    var sticky = getPosition(elem);
    function getPosition(element) {
      var yPosition = 0;
      while(element) {
        yPosition += (element.offsetTop - element.scrollTop + element.clientTop);
        element = element.offsetParent;
      }
      return  yPosition ;
    }

    // Toggle sticky header
    function myFunction() {
      if (window.pageYOffset > sticky) {
        clone.classList.add("star-sticky");
      } else {
        clone.classList.remove("star-sticky");
      }

    }
    elem.after(clone);
    $(".cloned--star-dashboard--header").wrap('<div class="star-dashboard--sticky-wrapper" tabindex="-1"><div class="star-dashboard--container"></div>');

    $(".star-dashboard--sticky-wrapper th").css({
      'width': ($(".star-dashboard--table thead th").innerWidth() + 'px')
    });

    // Enable scrolling on sticky header and other tables to match
    $('.star-dashboard--accordion .accordion-item .accordion-body').scroll(function() {
        var scrolled = $(this).scrollLeft();
        $('.star-dashboard--container').scrollLeft(scrolled);
        $('.star-dashboard--accordion .accordion-item .accordion-body').scrollLeft(scrolled);
    });

  });
});