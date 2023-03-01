define(['TYPO3/CMS/Backend/ImageManipulation', 'imagesloaded', 'TYPO3/CMS/Backend/FormEngineValidation'], function(ImageManipulation, imagesloaded, validation) {
  class RotationModule {
    constructor(imageManipulation) {
      this.imageManipulation = imageManipulation
      this.imageManipulation.initializeCropperModal = this.initializeCropperModal
      this.imageManipulation.updatePreviewThumbnail = this.updatePreviewThumbnail
      this.imageManipulation.setCropArea = this.setCropArea
      this.imageManipulation.update = this.update
      this.imageManipulation.save = this.save
    }
    initializeCropperModal = () => {
      const cropImageSelector  = this.imageManipulation.currentModal.find(ImageManipulation.cropImageSelector);
      imagesloaded(cropImageSelector.get(0), () => {
        this.imageManipulation.init();
        this.initialize();
        $(this.imageManipulation.cropper.element).on('ready', () => {
          setTimeout(() => {
            this.imageManipulation.resetButton.trigger('click')
          })
        });
        this.data = {}
        let variants = JSON.parse(this.imageManipulation.trigger.attr("data-crop-variants"))
        this.imageManipulation.cropVariantTriggers.each((index, button) => {
          if (variants.hasOwnProperty(button.dataset.cropVariantId)) {
            this.data[button.dataset.cropVariantId] = {
              id: button.dataset.cropVariantId,
              rotate: variants.rotate ?? 0
            }
          }
        })
      });
    }
    updatePreviewThumbnail = (currentCropVariant, activeCropVariantTrigger) => {
      const $thumbnailCropArea = activeCropVariantTrigger.find(".t3js-cropper-preview-thumbnail-crop-area"),
        $thumbnailCropImage = activeCropVariantTrigger.find(".t3js-cropper-preview-thumbnail-crop-image"),
        $thumbnailFocusArea = activeCropVariantTrigger.find(".t3js-cropper-preview-thumbnail-focus-area"),
        canvasData = ImageManipulation.cropper.getCanvasData();

      const cropAreaCss = {
        height: RotationModule.toCssPercent(currentCropVariant.cropArea.height / canvasData.naturalHeight),
        left: RotationModule.toCssPercent(currentCropVariant.cropArea.x / canvasData.naturalWidth),
        top: RotationModule.toCssPercent(currentCropVariant.cropArea.y / canvasData.naturalHeight),
        width: RotationModule.toCssPercent(currentCropVariant.cropArea.width / canvasData.naturalWidth),
      }
      $thumbnailCropArea.css(cropAreaCss);

      if (currentCropVariant.focusArea) {
        const focusAreaCss = {
          height: RotationModule.toCssPercent(currentCropVariant.focusArea.height),
          left: RotationModule.toCssPercent(currentCropVariant.focusArea.x),
          top: RotationModule.toCssPercent(currentCropVariant.focusArea.y),
          width: RotationModule.toCssPercent(currentCropVariant.focusArea.width)
        }
        $thumbnailFocusArea.css(focusAreaCss)
      }

      let thumbnailCropAreaCss = $thumbnailCropArea.css(["width", "height", "left", "top"]);
      const height = parseFloat(thumbnailCropAreaCss.height) * (1 / (currentCropVariant.cropArea.height / canvasData.naturalHeight)) + "px"
      const width = parseFloat(thumbnailCropAreaCss.width) * (1 / (currentCropVariant.cropArea.width / canvasData.naturalWidth)) + "px"

      const cropImageCss = {
        height: RotationModule.isLandscapeOrientation(ImageManipulation.cropper.getData().rotate) ? height : width,
        margin: -1 * parseFloat(thumbnailCropAreaCss.left) + "px",
        marginTop: -1 * parseFloat(thumbnailCropAreaCss.top) + "px",
        width: RotationModule.isLandscapeOrientation(ImageManipulation.cropper.getData().rotate) ?  width : height,
      }

      $thumbnailCropImage.css(cropImageCss);
    }
    setCropArea = (cropArea) => {
      const rotate = this.imageManipulation.data[this.imageManipulation.currentCropVariant.id].rotate || 0
      const newRotate = rotate - RotationModule.transformAngle(this.imageManipulation.cropper.getData().rotate)
      if (ImageManipulation.cropper.cropped && newRotate) {
        this.rotate(newRotate)
      }

      const aspectRatio = this.imageManipulation.currentCropVariant.allowedAspectRatios[this.imageManipulation.currentCropVariant.selectedRatio];
      const data = 0 === aspectRatio.value
        ? { height: cropArea.height, width: cropArea.width, x: cropArea.x, y: cropArea.y }
        : { height: cropArea.height, width: cropArea.height * aspectRatio.value, x: cropArea.x, y: cropArea.y }

      this.imageManipulation.cropper.setData(data);
    }
    save = (data) => {
      this.imageManipulation.cropVariantTriggers.each((index, button) => {
        if (data.hasOwnProperty(button.dataset.cropVariantId) && this.data.hasOwnProperty(button.dataset.cropVariantId)) {
          Object.assign(data[button.dataset.cropVariantId], this.data[button.dataset.cropVariantId]);
        }
      })
      const payload = RotationModule.serializeCropVariants(data);
      const $input = $("#" + this.imageManipulation.trigger.attr("data-field"));
      this.imageManipulation.trigger.attr("data-crop-variants", JSON.stringify(data));
      this.imageManipulation.setPreviewImages(data);
      Object.keys(data).forEach((i) => {
        const variant = data[i];
        const $image = this.imageManipulation.trigger.closest(".form-group").find(`.t3js-image-manipulation-preview[data-crop-variant-id="${i}"]`).find('img');
        const $rotate = this.imageManipulation.trigger.closest(".form-group").find(`.t3js-image-manipulation-rotate[data-crop-variant-id="${i}"]`);
        const offset = RotationModule.isLandscapeOrientation(variant.rotate) ? 0 : $image.width()/2 - $image.height()/2;
        
        $rotate.text(variant.rotate);
        $image.css({
          transform: `rotate(${variant.rotate}deg)`,
          left: `${$image.position().left - offset}px`,
          top: `${$image.position().top + offset}px`,
        })
      })
      
      $input.val(payload);
      validation.markFieldAsChanged($input);
      this.imageManipulation.currentModal.modal("hide");
    }
    update = (cropped) => {
      const extended = $.extend(!0, {}, cropped);
      const allowedAspectRatio = cropped.allowedAspectRatios[cropped.selectedRatio];
      
      this.imageManipulation.currentModal.find("[data-bs-option]").removeClass("active");
      this.imageManipulation.currentModal.find(`[data-bs-option="${cropped.selectedRatio}"]`).addClass("active");
      this.imageManipulation.setAspectRatio(allowedAspectRatio);
      this.imageManipulation.setCropArea(extended.cropArea);
      this.imageManipulation.currentCropVariant = $.extend(!0, {}, extended, cropped);
      this.imageManipulation.cropBox?.find(this.imageManipulation.coverAreaSelector).remove();
      
      if (this.imageManipulation.cropBox?.has(this.imageManipulation.focusAreaSelector).length) {
        this.imageManipulation.focusArea.resizable("destroy").draggable("destroy");
        this.imageManipulation.focusArea.remove()
      }
      if (cropped.focusArea) {
        if (RotationModule.isEmptyArea(cropped.focusArea)) {
          this.imageManipulation.currentCropVariant.focusArea = $.extend(!0, {}, this.imageManipulation.defaultFocusArea)
        }
        this.imageManipulation.initFocusArea(this.imageManipulation.cropBox);
        this.imageManipulation.scaleAndMoveFocusArea(this.imageManipulation.currentCropVariant.focusArea);
      }
      if (cropped.coverAreas) {
        this.imageManipulation.initCoverAreas(this.imageManipulation.cropBox, this.imageManipulation.currentCropVariant.coverAreas);
      }
      this.imageManipulation.updatePreviewThumbnail(this.imageManipulation.currentCropVariant, this.imageManipulation.activeCropVariantTrigger);
    }
    initialize = () => {
      setTimeout(() => {
        const $thumbnailContainer = this.imageManipulation.activeCropVariantTrigger.find(".cropper-preview-thumbnail")
        if ($thumbnailContainer.hasClass('wide')) {
          $thumbnailContainer.height($thumbnailContainer.height())
        } else {
          $thumbnailContainer.width($thumbnailContainer.width());
        }
      }, 10)

      this.imageManipulation.currentModal.find('#rotate-left').click(() => {
        this.rotate(-90);
      })
      this.imageManipulation.currentModal.find('#rotate-right').click(() => {
        this.rotate(90);
      })
    }
    rotate = (degree) => {
      const angle = RotationModule.transformAngle(this.imageManipulation.cropper.getData().rotate + degree)

      // Thumbnail and Preview Thumbnail

      const $thumbnailContainer = this.imageManipulation.activeCropVariantTrigger.find('.cropper-preview-thumbnail');
      const $thumbnail = $thumbnailContainer.find('.t3js-cropper-preview-thumbnail-image')
      const $thumbnailCanvas = $thumbnail.parent()
      const $previewThumbnail = $thumbnailContainer.find('.t3js-cropper-preview-thumbnail-crop-image')
      const $previewThumbnailCanvas = $previewThumbnail.parent()

      const orientation = $thumbnailContainer.hasClass('wide') ? 'horizontal' : 'vertical'

      if (!this.thumbnailHeight && !this.thumbnailWidth) {
        $thumbnail.height($thumbnail.height())
        $thumbnail.width($thumbnail.width())
        this.thumbnailHeight = $thumbnail.height();
        this.thumbnailWidth = $thumbnail.width();
      }

      let thumbnailOffset;
      if (orientation === 'horizontal') {
        thumbnailOffset = (this.thumbnailWidth - this.thumbnailHeight) / 2
      } else {
        thumbnailOffset = (this.thumbnailHeight - this.thumbnailWidth) / 2
      }
      
      if (RotationModule.isLandscapeOrientation(angle)) {
        $thumbnail.css('transform', 'rotate(' + angle + 'deg)')
        $thumbnailCanvas.css({
          left: '0px',
          top: '0px',
          height: this.thumbnailHeight + 'px',
          width: this.thumbnailWidth + 'px',
        })
      } else {
        const thumbnailTransform = [
          'rotate(' + angle + 'deg)',
          'translateX(' + (RotationModule.is90Vertical(angle, orientation) ? -thumbnailOffset : thumbnailOffset) + 'px)',
          'translateY(' + (RotationModule.is90Horizontal(angle, orientation) || RotationModule.is270Vertical(angle, orientation) ? thumbnailOffset : -thumbnailOffset) + 'px)',
        ]
        $thumbnail.css('transform', thumbnailTransform.join(' '))

        $thumbnailCanvas.css({
          left: (RotationModule.is90Vertical(angle, orientation) || RotationModule.is270Vertical(angle, orientation) ? -thumbnailOffset : thumbnailOffset) + 'px',
          top: (RotationModule.is90Horizontal(angle, orientation) ? -thumbnailOffset : thumbnailOffset) + 'px',
          height: this.thumbnailWidth + 'px',
          width: this.thumbnailHeight + 'px',
        })
      }

      let newThumbnailCanvasDataCss;
      if (orientation === 'horizontal') {
        const newThumbnailWidth = Math.round($thumbnailCanvas.width() * ($thumbnailContainer.height() / $thumbnailCanvas.height()));
        if (newThumbnailWidth > $thumbnailContainer.width()) {
          const newThumbnailHeight = Math.round($thumbnailCanvas.height() * ($thumbnailContainer.width() / $thumbnailCanvas.width()));
          newThumbnailCanvasDataCss = {
            height: newThumbnailHeight + 'px',
            width: $thumbnailContainer.width() + 'px',
            top: Math.floor(($thumbnailContainer.height() - newThumbnailHeight) / 2) + 'px',
            left: 0 + 'px',
          }
        } else {
          newThumbnailCanvasDataCss = {
            height: $thumbnailContainer.height() + 'px',
            width: newThumbnailWidth + 'px',
            top: 0 + 'px',
            left: Math.floor(($thumbnailContainer.width() - newThumbnailWidth) / 2) + 'px',
          }
        }
      } else {
        const newThumbnailHeight = Math.round($thumbnailCanvas.height() * ($thumbnailContainer.width() / $thumbnailCanvas.width()));

        if (newThumbnailHeight >= $thumbnailContainer.height()) {
          const newThumbnailWidth = Math.round($thumbnailCanvas.width() * ($thumbnailContainer.height() / $thumbnailCanvas.height()));
          newThumbnailCanvasDataCss = {
            height: $thumbnailContainer.height() + 'px',
            width: newThumbnailWidth + 'px',
            top: 0 + 'px',
            left: Math.floor(($thumbnailCanvas.width() - newThumbnailWidth) / 2) + 'px',
          }
        } else {
          newThumbnailCanvasDataCss = {
            height: newThumbnailHeight + 'px',
            width: $thumbnailContainer.width() + 'px',
            top: Math.floor(($thumbnailContainer.height() - newThumbnailHeight) / 2) + 'px',
            left: 0 + 'px'
          }
        }
      }

      let newThumbnailOffset
      if (orientation === 'horizontal') {
        newThumbnailOffset = parseFloat(newThumbnailCanvasDataCss.width) / parseFloat(newThumbnailCanvasDataCss.height) * thumbnailOffset
      } else {
        newThumbnailOffset = parseFloat(newThumbnailCanvasDataCss.height) / parseFloat(newThumbnailCanvasDataCss.width) * thumbnailOffset
      }
      

      $previewThumbnailCanvas.css({
        height: newThumbnailCanvasDataCss.height,
        width: newThumbnailCanvasDataCss.width,
      })

      $thumbnailCanvas.css({
        height: newThumbnailCanvasDataCss.height,
        width: newThumbnailCanvasDataCss.width,
        left: newThumbnailCanvasDataCss.left,
        top: newThumbnailCanvasDataCss.top
      })


      let thumbnailCss;
      if (orientation === 'horizontal') {
        thumbnailCss = {
          width: RotationModule.isLandscapeOrientation(angle) ? newThumbnailCanvasDataCss.width : newThumbnailCanvasDataCss.height,
          height: RotationModule.isLandscapeOrientation(angle) ? newThumbnailCanvasDataCss.height : newThumbnailCanvasDataCss.width,
          transform: RotationModule.isLandscapeOrientation(angle) ? 'rotate(' + angle + 'deg)' : 'rotate(' + angle + 'deg) translateX(' + (RotationModule.is90Horizontal(angle, orientation) ? newThumbnailOffset : -newThumbnailOffset) +  'px) translateY(' + (RotationModule.is90Horizontal(angle, orientation) ? newThumbnailOffset : -newThumbnailOffset) + 'px)'
        }
      } else {
        thumbnailCss = {
          width: RotationModule.isLandscapeOrientation(angle) ? newThumbnailCanvasDataCss.width : newThumbnailCanvasDataCss.height,
          height: RotationModule.isLandscapeOrientation(angle) ? newThumbnailCanvasDataCss.height : newThumbnailCanvasDataCss.width,
          transform: RotationModule.isLandscapeOrientation(angle) ? 'rotate(' + angle + 'deg)' : 'rotate(' + angle + 'deg) translateX(' + (RotationModule.is270Vertical(angle, orientation) ? newThumbnailOffset : -newThumbnailOffset) +  'px) translateY(' + (RotationModule.is270Vertical(angle, orientation) ? newThumbnailOffset : -newThumbnailOffset) + 'px)'
        }
      }

      $thumbnail.css(thumbnailCss)
      $previewThumbnail.css(thumbnailCss)

      // Main image

      const containerData = this.imageManipulation.cropper.getContainerData();

      this.imageManipulation.cropper.setCropBoxData({
        width: 2, height: 2, top: (containerData.height/ 2) - 1, left: (containerData.width / 2) - 1
      });

      this.imageManipulation.cropper.rotate(degree);

      const canvasData = this.imageManipulation.cropper.getCanvasData();
      const newWidth = canvasData.width * (containerData.height / canvasData.height);

      let newCanvasData;
      if (newWidth >= containerData.width) {
        const newHeight = canvasData.height * (containerData.width / canvasData.width);
        newCanvasData = {
          height: newHeight,
          width: containerData.width,
          top: (containerData.height - newHeight) / 2,
          left: 0
        }
      } else {
        newCanvasData = {
          height: containerData.height,
          width: newWidth,
          top: 0,
          left: (containerData.width - newWidth) / 2
        }
      }

      this.imageManipulation.cropper.setCanvasData(newCanvasData);
      this.imageManipulation.cropper.setCropBoxData(newCanvasData);
      
      this.data[this.imageManipulation.currentCropVariant.id].rotate = angle

      this.updateRotateInformation()
    }
    updateRotateInformation = () => {
      if (this.data) {
        this.imageManipulation.currentModal.find('.t3js-cropper-info-rotate').
          text(
            (this.data[this.imageManipulation.currentCropVariant.id].rotate ??
              0) + '°'
          )
      }
    }
    static transformAngle = (angle) => {
      while (angle < 0)  {
        angle = angle + 360;
      }

      while (angle >= 360) {
        angle = angle - 360;
      }
      return angle
    }
    static isLandscapeOrientation = (angle) => {
      angle = RotationModule.transformAngle(angle)
      return angle === 0 || angle === 180
    }
    static is90Horizontal = (angle, orientation) => {
      angle = RotationModule.transformAngle(angle)
      return angle === 90 && orientation === 'horizontal'
    }
    static is90Vertical = (angle, orientation) =>
    {
      angle = RotationModule.transformAngle(angle)
      return angle === 90 && orientation === 'vertical'
    }
    static is270Vertical = (angle, orientation) =>
    {
      angle = RotationModule.transformAngle(angle)
      return angle === 270 && orientation === 'vertical'
    }
    static toCssPercent = (value) => {
      return 100 * value + "%";
    }
    static isEmptyArea(object) {
      return $.isEmptyObject(object);
    }
    static serializeCropVariants = (data) => {
      return JSON.stringify(
        data, (data, e) => ("id" === data || "title" === data || "allowedAspectRatios" === data || "coverAreas" === data ? void 0 : e)
      );
    }
  }
  
  return new RotationModule(ImageManipulation)
});
