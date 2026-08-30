(function ($) {
	"use strict";

	// gsap
	gsap.config({
		nullTargetWarn: false,
	});

	// Immediately hide elements to prevent flashing before preloader/animations
	xb_init_hide();

	function xb_init_hide() {
		gsap.set(".heroTitleUp, .xbFadeUp, .xbFadeUpContent, .xbFadeUpImg, .xbFadeUpItem", { autoAlpha: 0 });
	}

	$(window).on('load', function () {
		preloader();
	});

	/*------------------------------------------
	= preloader
	-------------------------------------------*/
	function preloader() {
		const loader = $('#preloader');
		const logo = $(".xb-loader-logo");
		if (loader.length) {
			const tl = gsap.timeline();

			// Simply fade out the logo to avoid clashing with the CSS zoom animation
			// This prevents the "blink" caused by transform resets
			tl.to(logo, {
				autoAlpha: 0,
				duration: 0.6,
				ease: "power2.inOut"
			}, "+=0.2")
				.to(loader, {
					yPercent: -100,
					duration: 0.8,
					ease: "expo.inOut",
					onComplete: function () {
						loader.addClass('preloader-hidden');
						loader.hide();
					}
				}, "-=0.4")
				.add(() => {
					xb_reveal_animation();
					if (typeof wowAnimation === 'function') {
						wowAnimation();
					}
				}, "-=0.3");
		} else {
			xb_reveal_animation();
			if (typeof wowAnimation === 'function') {
				wowAnimation();
			}
		}
	}


	/*------------------------------------------
	= dynamic reveal animation
	-------------------------------------------*/
	function xb_reveal_animation() {
		// Hide logic moved to xb_init_hide() for performance

		// Helper function to parse delay
		function getDelay(el) {
			let attr = el.attr('data-animation-delay') || el.attr('animation-delay');
			if (!attr) return 0;
			if (attr.includes('ms')) return parseFloat(attr) / 1000;
			if (attr.includes('s')) return parseFloat(attr);
			return parseFloat(attr) / 1000; // Default to ms if unitless
		}

		// Hero Title Reveal (Scale + Blur + Y)
		$('.heroTitleUp').each(function () {
			let el = $(this);
			let delay = getDelay(el);

			gsap.fromTo(el, {
				scale: 0.9,
				y: 50,
				skewY: 3,
				autoAlpha: 0,
				filter: "blur(20px)"
			}, {
				scale: 1,
				y: 0,
				skewY: 0,
				autoAlpha: 1,
				filter: "blur(0px)",
				duration: 1.2,
				delay: delay,
				ease: "expo.out",
				scrollTrigger: {
					trigger: el,
					start: "top 95%",
					toggleActions: "play none none none"
				}
			});
		});

		// Global Section Title Reveal (Word-by-Word Reveal)
		$('.xbFadeUp').each(function () {
			let el = $(this);
			let delay = getDelay(el);

			// Professional manual word splitting
			let content = el.text().trim();
			let words = content.split(/\s+/);
			el.empty();

			words.forEach((word, i) => {
				let wrapper = $('<span class="xb-word-cache" style="display: inline-block; overflow: hidden;"></span>');
				let inner = $(`<span class="xb-word-inner" style="display: inline-block;">${word}${i < words.length - 1 ? '&nbsp;' : ''}</span>`);
				wrapper.append(inner);
				el.append(wrapper);
			});

			let inners = el.find('.xb-word-inner');
			gsap.fromTo(inners, {
				yPercent: 100,
				rotateZ: 3,
				autoAlpha: 0
			}, {
				yPercent: 0,
				rotateZ: 0,
				autoAlpha: 1,
				duration: 1,
				delay: delay,
				stagger: 0.05,
				ease: "power4.out",
				scrollTrigger: {
					trigger: el,
					start: "top 90%",
					toggleActions: "play none none none"
				}
			});
		});

		// Image Reveal (Deep Scale + Y)
		$('.xbFadeUpImg').each(function () {
			let el = $(this);
			let delay = getDelay(el);

			gsap.fromTo(el, {
				y: 80,
				scale: 0.9,
				autoAlpha: 0,
			}, {
				y: 0,
				scale: 1,
				autoAlpha: 1,
				duration: 1.2,
				delay: delay,
				ease: "power2.out",
				scrollTrigger: {
					trigger: el,
					start: "top 95%",
					toggleActions: "play none none none"
				}
			});
		});

		// Content/Text Reveal (Simple Y + Alpha)
		$('.xbFadeUpContent').each(function () {
			let el = $(this);
			let delay = getDelay(el);

			gsap.fromTo(el, {
				y: 20,
				autoAlpha: 0
			}, {
				y: 0,
				autoAlpha: 1,
				duration: 0.8,
				delay: delay,
				ease: "power2.out",
				scrollTrigger: {
					trigger: el,
					start: "top 95%",
					toggleActions: "play none none none"
				}
			});
		});

		// Item Reveal (Medium Y + Alpha)
		$('.xbFadeUpItem').each(function () {
			let el = $(this);
			let delay = getDelay(el);

			gsap.fromTo(el, {
				y: 30,
				autoAlpha: 0
			}, {
				y: 0,
				autoAlpha: 1,
				duration: 1,
				delay: delay,
				ease: "power2.out",
				scrollTrigger: {
					trigger: el,
					start: "top 95%",
					toggleActions: "play none none none"
				}
			});
		});
	}

	/*------------------------------------------
	= back to top
	-------------------------------------------*/
	$(window).scroll(function () {
		if ($(this).scrollTop() > 500) {
			$('.xb-backtotop').addClass('active');
		} else {
			$('.xb-backtotop').removeClass('active');
		}
	});
	$(function () {
		$(".scroll").on('click', function () {
			$("html,body").animate({ scrollTop: 0 }, "slow");
			return false
		});
	});

	/*------------------------------------------
	= sticky header
	-------------------------------------------*/
	function stickyHeader() {
		var scrollDirection = "";
		var lastScrollPosition = 0;

		// Clone and make header sticky if the element with class 'xb-header' exists
		if ($('.xb-header').length) {
			$('.xb-header').addClass('original').clone(true).insertAfter('.xb-header').addClass('xb-header-area-sticky xb-sticky-stt').removeClass('original');
		}

		// Handle scroll events
		$(window).on("scroll", function () {
			var currentScrollPosition = $(window).scrollTop();

			// Determine scroll direction
			scrollDirection = currentScrollPosition < lastScrollPosition ? "up" : "down";
			lastScrollPosition = currentScrollPosition;

			// Check if element with ID 'xb-header-area' has class 'is-sticky'
			if ($("#xb-header-area").hasClass("is-sticky")) {
				// Add or remove classes based on scroll position for sticky header and mobile header
				if (lastScrollPosition > 100) {
					$(".xb-header-area-sticky.xb-sticky-stb").addClass("xb-header-fixed");
				} else {
					$(".xb-header-area-sticky.xb-sticky-stb").removeClass("xb-header-fixed");
				}

				// Add or remove classes for sticky header based on scroll direction
				if (scrollDirection === "up" && lastScrollPosition > 100) {
					$(".xb-header-area-sticky.xb-sticky-stt").addClass("xb-header-fixed");
				} else {
					$(".xb-header-area-sticky.xb-sticky-stt").removeClass("xb-header-fixed");
				}
			}
		});
	}
	stickyHeader();

	/*------------------------------------------
	= feature tab click
	-------------------------------------------*/
	function feature_click_tabs() {
		const items = $('.xbf-feature-item');
		const views = $('.xbf-mockup-view');
		const mockupContainer = $('.xbf-mockup-container');

		if (items.length) {
			$(document).on('click', '.xbf-feature-item', function () {
				const $this = $(this);
				if ($this.hasClass('active')) return; // Already active

				const index = $this.data('index');

				const imageHeight = 220; // Reverted to 220px as per CSS
				const gap = 30;
				const viewStep = imageHeight + gap; // 250px

				// Perfect Balance: 0.5s Transition
				const tl = gsap.timeline({ defaults: { duration: 0.5, ease: "power3.inOut" } });

				// 1. Update Items active state
				items.removeClass('active');
				$this.addClass('active');

				// 2. Synchronized Content and Image Animation
				items.each(function (i) {
					const wrapper = $(this).find('.content-wrapper');
					const isActive = $(this).hasClass('active');

					tl.to(wrapper, {
						height: isActive ? 'auto' : 0,
						opacity: isActive ? 1 : 0,
					}, 0);
				});

				// 3. Balanced Image Slide to TOP
				const offset = index * viewStep;
				tl.to(mockupContainer, {
					y: -offset,
					duration: 0.5,
				}, 0);

				// 4. Update Images active state
				views.removeClass('active');
				views.eq(index).addClass('active');
			});

			// Initial trigger - Set first item as active instantly without delay
			const firstItem = items.first();
			const firstIndex = firstItem.data('index');
			const firstWrapper = firstItem.find('.content-wrapper');

			firstItem.addClass('active');
			gsap.set(firstWrapper, { height: 'auto', opacity: 1 });
			gsap.set(mockupContainer, { y: -(firstIndex * 250) });
			views.eq(firstIndex).addClass('active');
		}
	}
	$(document).ready(function () {
		feature_click_tabs();
	});

	/*------------------------------------------
	= header search
	-------------------------------------------*/
	$(".header-search-btn").on("click", function (e) {
		e.preventDefault();
		$(".header-search-form-wrapper").addClass("open");
		$('.header-search-form-wrapper input[type="search"]').focus();
		$('.body-overlay').addClass('active');
	});
	$(".xb-search-close").on("click", function (e) {
		e.preventDefault();
		$(".header-search-form-wrapper").removeClass("open");
		$("body").removeClass("active");
		$('.body-overlay').removeClass('active');
	});

	/*------------------------------------------
	= sidebar
	-------------------------------------------*/
	$('.sidebar-menu-close, .body-overlay').on('click', function () {
		$('.offcanvas-sidebar').removeClass('active');
		$('.body-overlay').removeClass('active');
	});

	$('.offcanvas-sidebar-btn').on('click', function () {
		$('.offcanvas-sidebar').addClass('active');
		$('.body-overlay').addClass('active');
	});
	$('.body-overlay').on('click', function () {
		$(this).removeClass('active');
		$(".header-search-form-wrapper").removeClass("open");
	});


	/*------------------------------------------
	= mobile menu
	-------------------------------------------*/
	$('.xb-nav-hidden li.menu-item-has-children > a').append('<span class="xb-menu-toggle"></span>');
	$('.xb-header-menu li.menu-item-has-children, .xb-menu-primary li.menu-item-has-children').append('<span class="xb-menu-toggle"></span>');
	$('.xb-menu-toggle').on('click', function () {
		if (!$(this).hasClass('active')) {
			$(this).closest('ul').find('.xb-menu-toggle.active').toggleClass('active');
			$(this).closest('ul').find('.sub-menu.active').toggleClass('active').slideToggle();
		}
		$(this).toggleClass('active');
		$(this).closest('.menu-item').find('> .sub-menu').toggleClass('active');
		$(this).closest('.menu-item').find('> .sub-menu').slideToggle();
	});

	$('.xb-nav-hidden li.menu-item-has-children > a').click(function (e) {
		var target = $(e.target);
		if ($(this).attr('href') === '#' && !(target.is('.xb-menu-toggle'))) {
			e.stopPropagation();
			if (!$(this).find('.xb-menu-toggle').hasClass('active')) {
				$(this).closest('ul').find('.xb-menu-toggle.active').toggleClass('active');
				$(this).closest('ul').find('.sub-menu.active').toggleClass('active').slideToggle();
			}
			$(this).find('.xb-menu-toggle').toggleClass('active');
			$(this).closest('.menu-item').find('> .sub-menu').toggleClass('active');
			$(this).closest('.menu-item').find('> .sub-menu').slideToggle();
		}
	});
	$(".xb-nav-mobile").on('click', function () {
		$(this).toggleClass('active');
		$('.xb-header-menu').toggleClass('active');
	});

	$(".xb-menu-close, .xb-header-menu-backdrop").on('click', function () {
		$(this).removeClass('active');
		$('.xb-header-menu').removeClass('active');
	});

	/*------------------------------------------
	= nice select
	-------------------------------------------*/
	$('select').niceSelect();

	/*------------------------------------------
	= data background and bg color
	-------------------------------------------*/
	$("[data-background]").each(function () {
		$(this).css("background-image", "url(" + $(this).attr("data-background") + ") ")
	})
	$("[data-bg-color]").each(function () {
		$(this).css("background-color", $(this).attr("data-bg-color"));

	});

	/*------------------------------------------
	= aos animation
	-------------------------------------------*/
	function wowAnimation() {
		var wow = new WOW({
			boxClass: 'wow',
			animateClass: 'animated',
			offset: 0,
			mobile: false,
			live: true
		});
		wow.init();
	}

	/*------------------------------------------
	= counter
	-------------------------------------------*/
	if ($(".xbo").length) {
		$('.xbo').appear();
		$(document.body).on('appear', '.xbo', function (e) {
			var odo = $(".xbo");
			odo.each(function () {
				var countNumber = $(this).attr("data-count");
				$(this).html(countNumber);
			});
			window.xboOptions = {
				format: 'd',
			};
		});
	}

	if ($(".xbo_trigger").length) {
		var odo = $(".xbo_trigger");
		odo.each(function () {
			var countNumber = $(this).attr("data-count");
			var odometerInstance = new Odometer({
				el: this,
				value: 0,
				format: 'd',
			});
			odometerInstance.render();
			odometerInstance.update(countNumber);
		});
		$('.xbo_trigger').appear();
		$(document.body).on('appear', '.xboh', function (e) {
			// This event handler can be empty or used for additional functionality if needed
		});
	}

	/*------------------------------------------
	= smooth scroll
	-------------------------------------------*/
	const lenis = new Lenis({
		duration: .8,
		smoothWheel: true,
	});

	function raf(time) {
		lenis.raf(time);
		requestAnimationFrame(raf);
	}
	requestAnimationFrame(raf);

	/*------------------------------------------
	= isotop
	-------------------------------------------*/
	$('.grid').imagesLoaded(function () {
		var $grid = $('.grid').isotope({
			itemSelector: '.grid-item',
			percenxbosition: true,
			masonry: {
				// use outer width of grid-sizer for columnWidth
				columnWidth: '.grid-item',
			}
		});

		// filter items on button click
		$('.xb-project-menu').on('click', 'button', function () {
			var filterValue = $(this).attr('data-filter');
			$grid.isotope({ filter: filterValue });
		});
	});

	//for menu active class
	$('.xb-project-menu button').on('click', function (event) {
		$(this).siblings('.active').removeClass('active');
		$(this).addClass('active');
		event.preventDefault();
	});

	/*------------------------------------------
	= Background Parallax - Start
	-------------------------------------------*/
	$('.parallaxie').parallaxie({
		speed: 0.5,
		offset: 0,
	});

	/*------------------------------------------
	= story slide
	-------------------------------------------*/
	var slider = new Swiper(".xb-story-slider", {
		loop: true,
		speed: 400,
		spaceBetween: 50,
		slidesPerView: 1,
		centeredSlides: false,
		autoplay: {
			delay: 4000,
			disableOnInteraction: false
		},
		pagination: {
			el: ".swiper-pagination",
		},
	});

	/*------------------------------------------
	= team slide
	-------------------------------------------*/
	var slider = new Swiper(".fs-story-slider", {
		loop: true,
		speed: 400,
		spaceBetween: 30,
		slidesPerView: 4,
		autoplay: {
			enabled: true,
			delay: 6000
		},
		navigation: {
			nextEl: ".swiper-button-next",
			prevEl: ".swiper-button-prev",
		},
		breakpoints: {
			'1200': {
				slidesPerView: 4,
			},
			'768': {
				slidesPerView: 3,
			},
			'576': {
				slidesPerView: 2,
				spaceBetween: 20,
			},
			'0': {
				slidesPerView: 1,
			},

		},
	});

	/*------------------------------------------
	= project slide
	-------------------------------------------*/
	var slider = new Swiper(".blog-slider", {
		loop: true,
		speed: 400,
		spaceBetween: 0,
		slidesPerView: 1,
		centeredSlides: true,
		autoplay: {
			enabled: true,
			delay: 6000
		},
		pagination: {
			el: ".swiper-pagination",
		},
		navigation: {
			nextEl: ".swiper-button-next",
			prevEl: ".swiper-button-prev",
		},
	});

	/*------------------------------------------
	= testimonial slide
	-------------------------------------------*/
	var swiper = new Swiper(".xb-testimonial-author", {
		spaceBetween: 1,
		slidesPerView: 6,
		autoplay: {
			enabled: true,
			delay: 6000
		},
	});
	var swiper2 = new Swiper(".xb-testimonial-slider", {
		loop: true,
		speed: 400,
		spaceBetween: 0,
		slidesPerView: 1,
		navigation: {
			nextEl: ".swiper-button-next",
			prevEl: ".swiper-button-prev",
		},
		thumbs: {
			swiper: swiper,
		},
	});

	/*------------------------------------------
	= inhover active
	-------------------------------------------*/
	$(".xb-mouseenter").on('mouseenter', function () {
		$(".xb-mouseenter").removeClass("active");
		$(this).addClass("active");
	});
	//click
	$(".xb-mouseenter2").on('click', function () {
		$(".xb-mouseenter2").removeClass("active");
		$(this).addClass("active");
	});

	document.addEventListener("DOMContentLoaded", () => {
		const closeBtn = document.querySelector(".close-btn");
		const videoContainer = document.querySelector(".xb-video-container");

		if (closeBtn && videoContainer) {
			closeBtn.addEventListener("click", () => {
				videoContainer.classList.toggle("active");
			});
		}
	});

	/*------------------------------------------
	= magnificPopup
	-------------------------------------------*/
	$('.popup-image').magnificPopup({
		type: 'image',
		gallery: {
			enabled: true
		}
	});
	$('.popup-video').magnificPopup({
		type: 'iframe',
		mainClass: 'mfp-zoom-in',
	});

	/*------------------------------------------
	= Accordion Box
	-------------------------------------------*/
	if ($(".accordion_box").length) {
		$(".accordion_box").on("click", ".acc-btn", function () {
			var outerBox = $(this).parents(".accordion_box");
			var target = $(this).parents(".accordion");

			if ($(this).next(".acc_body").is(":visible")) {
				$(this).removeClass("active");
				$(this).next(".acc_body").slideUp(300);
				$(outerBox).children(".accordion").removeClass("active-block");
			} else {
				$(outerBox).find(".accordion .acc-btn").removeClass("active");
				$(this).addClass("active");
				$(outerBox).children(".accordion").removeClass("active-block");
				$(outerBox).find(".accordion").children(".acc_body").slideUp(300);
				target.addClass("active-block");
				$(this).next(".acc_body").slideDown(300);
			}
		});
	}

	/*------------------------------------------
	= marquee
	-------------------------------------------*/
	$('.marquee-left').marquee({
		speed: 20,
		gap: 0,
		delayBeforeStart: 0,
		direction: 'left',
		duplicated: true,
		pauseOnHover: false,
		startVisible: true,
	});
	$('.marquee-left-2').marquee({
		speed: 20,
		gap: 0,
		delayBeforeStart: 0,
		direction: 'left',
		duplicated: true,
		pauseOnHover: true,
		startVisible: true,
	});
	$('.marquee-right').marquee({
		speed: 20,
		gap: 0,
		delayBeforeStart: 0,
		direction: 'right',
		duplicated: true,
		pauseOnHover: false,
		startVisible: true,
	});

	/*------------------------------------------
	= button text hover animation
	-------------------------------------------*/
	document.fonts.ready.then(function () {
		const splitLink = new SplitText("[data-link-shadow]", {
			type: "lines, chars",
			mask: "lines",
			charsClass: "chars-link",
		});

		gsap.set(splitLink.chars, { yPercent: 0 });

		document.querySelectorAll("[data-split-link]").forEach((btnLink) => {
			btnLink.addEventListener("mouseenter", () => {
				gsap.to(btnLink.querySelectorAll("[data-split-link] .chars-link"), {
					yPercent: -85,
					duration: 0.3,
					stagger: 0.025,
					ease: "power2.out"
				});
			});

			btnLink.addEventListener("mouseleave", () => {
				gsap.to(btnLink.querySelectorAll("[data-split-link] .chars-link"), {
					yPercent: 0,
					duration: 0.3,
					stagger: 0.025,
					ease: "power2.out"
				});
			});
		});
	});

	/*------------------------------------------
	= hover class active and change elements
	-------------------------------------------*/
	function service_animation() {
		var active_bg = $(".xb-item-list .active-bg");
		var element = $(".xb-item-list .current");

		function activeServiceList(active_bg, e) {
			if (!e.length) {
				active_bg.css({ height: "100%" });
				return false;
			}
			var topOff = e.offset().top;
			var height = e.outerHeight();
			var menuTop = $(".xb-item-list").offset().top;

			active_bg.css({ top: topOff - menuTop + "px", height: height + "px" });
			e.closest(".list-item").removeClass("mleave").addClass("current");
			e.closest(".list-item").siblings().removeClass("current").addClass("mleave");
		}

		$(".xb-item-list .list-item").on("mouseenter", function () {
			var e = $(this);
			var index = e.index();

			activeServiceList(active_bg, e);
			$(".xb-content-list .content-item").removeClass("active").eq(index).addClass("active");
		});

		$(".xb-item-list").on("mouseleave", function () {
			element = $(".xb-item-list .current");
			var index = element.index();

			activeServiceList(active_bg, element);

			$(".xb-content-list .content-item").removeClass("active").eq(index).addClass("active");

			element.closest(".list-item").siblings().removeClass("mleave");
		});

		$(".xb-item-list .list-item").on("click", function () {
			$(".xb-item-list .list-item").removeClass("current");
			$(this).addClass("current");

			var index = $(this).index();
			$(".xb-content-list .content-item").removeClass("active").eq(index).addClass("active");
		});
		activeServiceList(active_bg, element);
	}
	service_animation();

	/*------------------------------------------
	= price Toggle button
	-------------------------------------------*/
	function priceToggle() {
		const monthly = document.getElementById('pills-home-tab');
		const yearly = document.getElementById('pills-profile-tab');
		const box = document.querySelector('.xb-pricing-nav');

		if (!monthly || !yearly || !box) return;

		yearly.addEventListener('click', () => box.classList.add('is-active'));
		monthly.addEventListener('click', () => box.classList.remove('is-active'));
	}
	document.addEventListener("DOMContentLoaded", priceToggle);

	/*------------------------------------------
	= active toggle btn
	-------------------------------------------*/
	$(".switch_btn").on('click', function () {
		$(this).toggleClass("active");
		$(".pricing_block").toggleClass("active");
	});

	/*------------------------------------------
	= fade-class-active
	-------------------------------------------*/
	if ($(".xb_fade_anim").length > 0) {
		gsap.utils.toArray(".xb_fade_anim").forEach((item) => {
			let xb_fade_offset = item.getAttribute("data-fade-offset") || 40,
				xb_duration_value = item.getAttribute("data-duration") || 0.75,
				xb_fade_direction = item.getAttribute("data-fade-from") || "bottom",
				xb_onscroll_value = item.getAttribute("data-on-scroll") || 1,
				xb_delay_value = item.getAttribute("data-delay") || 0.15,
				xb_ease_value = item.getAttribute("data-ease") || "power2.out",
				xb_anim_setting = {
					opacity: 0,
					ease: xb_ease_value,
					duration: xb_duration_value,
					delay: xb_delay_value,
					x: (xb_fade_direction == "left" ? -xb_fade_offset : (xb_fade_direction == "right" ? xb_fade_offset : 0)),
					y: (xb_fade_direction == "top" ? -xb_fade_offset : (xb_fade_direction == "bottom" ? xb_fade_offset : 0)),
				};
			if (xb_onscroll_value == 1) {
				xb_anim_setting.scrollTrigger = {
					trigger: item,
					start: 'top 85%',
					// markers: true,
				};
			}
			gsap.from(item, xb_anim_setting);
		});
	}

	/*------------------------------------------
	= auto tab class change animation
	-------------------------------------------*/
	document.addEventListener("DOMContentLoaded", function () {
		let tabs = document.querySelectorAll('.fs-dashboard-nav .nav-link');
		if (!tabs.length) return;

		let index = 0;
		let intervalTime = 3000;

		setInterval(() => {
			index = (index + 1) % tabs.length;

			if (typeof bootstrap !== "undefined" && bootstrap.Tab) {
				let nextTab = new bootstrap.Tab(tabs[index]);
				nextTab.show();
			}
		}, intervalTime);
	});

	/*------------------------------------------
	= auto active class
	-------------------------------------------*/
	document.addEventListener("DOMContentLoaded", () => {

		const items = document.querySelectorAll(".fs-process-item");
		const images = document.querySelectorAll(".fs-process-image-inner .img");

		let index = 0;
		let interval;

		const setActive = (i) => {

			// safety check
			if (!items[i] || !images[i]) return;

			items.forEach(el => el.classList.remove("active"));
			images.forEach(el => el.classList.remove("active"));

			items[i].classList.add("active");
			images[i].classList.add("active");

			index = i;
		};

		const nextSlide = () => {
			index = (index + 1) % items.length;
			setActive(index);
		};

		const startAuto = () => {
			if (items.length === 0) return; // safety
			interval = setInterval(nextSlide, 3000);
		};

		const resetAuto = () => {
			clearInterval(interval);
			startAuto();
		};

		startAuto();

		items.forEach((item, i) => {
			item.addEventListener("click", () => {
				setActive(i);
				resetAuto();
			});
		});

	});

})(jQuery);
