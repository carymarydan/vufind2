/*global deparam, extractClassParams, htmlEncode, Lightbox, path, vufindString */

/**
 * Functions and event handlers specific to record pages.
 */

function checkRequestIsValid(element, requestURL, requestType, blockedClass) {
  var recordId = requestURL.match(/\/Record\/([^\/]+)\//)[1];
  var vars = {}, hash;
  var hashes = requestURL.slice(requestURL.indexOf('?') + 1).split('&');

  for(var i = 0; i < hashes.length; i++)
  {
    hash = hashes[i].split('=');
    var x = hash[0];
    var y = hash[1];
    vars[x] = y;
  }
  vars['id'] = recordId;

  var url = path + '/AJAX/JSON?' + $.param({method:'checkRequestIsValid', id: recordId, requestType: requestType, data: vars});
  $.ajax({
    dataType: 'json',
    cache: false,
    url: url,
    success: function(response) {
      if (response.status == 'OK') {
        if (response.data.status) {
          $(element).removeClass('disabled').html('<i class="icon-flag"></i>&nbsp;'+response.data.msg);
        } else {
          $(element).remove();
        }
      } else if (response.status == 'NEED_AUTH') {
        $(element).replaceWith('<span class="' + blockedClass + '">' + response.data.msg + '</span>');
      }
    }
  });
}

function setUpCheckRequest() {
  $('.checkRequest').each(function(i) {
    if($(this).hasClass('checkRequest')) {
      var isValid = checkRequestIsValid(this, this.href, 'Hold', 'holdBlocked');
    }
  });
}

function setUpCheckStorageRetrievalRequest() {
  $('.checkStorageRetrievalRequest').each(function(i) {
    if($(this).hasClass('checkStorageRetrievalRequest')) {
      var isValid = checkRequestIsValid(this, this.href, 'StorageRetrievalRequest',
          'StorageRetrievalRequestBlocked');
    }
  });
}

function deleteRecordComment(element, recordId, recordSource, commentId) {
  var url = path + '/AJAX/JSON?' + $.param({method:'deleteRecordComment',id:commentId});
  $.ajax({
    dataType: 'json',
    url: url,
    success: function(response) {
      if (response.status == 'OK') {
        $($(element).parents('.comment')[0]).remove();
      }
    }
  });
}

function refreshCommentList(recordId, recordSource) {
  var url = path + '/AJAX/JSON?' + $.param({method:'getRecordCommentsAsHTML',id:recordId,'source':recordSource});
  $.ajax({
    dataType: 'json',
    url: url,
    success: function(response) {
      // Update HTML
      if (response.status == 'OK') {
        $('#commentList').empty();
        $('#commentList').append(response.data);
        $('input[type="submit"]').button('reset');
        $('.delete').unbind('click').click(function() {
          var commentId = $(this).attr('id').substr('recordComment'.length);
          deleteRecordComment(this, recordId, recordSource, commentId);
          return false;
        });
      }
    }
  });
}

function registerAjaxCommentRecord() {
  // Form submission
  $('form[name="commentRecord"]').unbind('submit').submit(function(){
    var form = this;
    var id = form.id.value;
    var recordSource = form.source.value;
    var url = path + '/AJAX/JSON?' + $.param({method:'commentRecord'});
    var data = {
      comment:form.comment.value,
      id:id,
      source:recordSource
    };
    $.ajax({
      type: 'POST',
      url:  url,
      data: data,
      dataType: 'json',
      success: function(response) {
        var form = 'form[name="commentRecord"]';
        if (response.status == 'OK') {
          refreshCommentList(id, recordSource);
          $(form).find('textarea[name="comment"]').val('');
        } else if (response.status == 'NEED_AUTH') {
          data['loggingin'] = true;
          Lightbox.addCloseAction(function() {
            $.ajax({
              type: 'POST',
              url:  url,
              data: data,
              dataType: 'json'
            });
          });
          return Lightbox.get('Record', 'AddComment', data, data);
        } else {
          $('#modal').find('.modal-body').html(response.data+'!');
          $('#modal').find('.modal-header h3').html('Error!');
          $('#modal').modal('show');
        }
      }
    });
    $(form).find('input[type="submit"]').button('loading');
    return false;
  });
  // Delete links
  $('.delete').click(function(){deleteRecordComment(this, $('.hiddenId').val(), $('.hiddenSource').val(), this.id.substr(13));return false;});
}

$(document).ready(function(){
  var id = document.getElementById('record_id').value;
  
  // register the record comment form to be submitted via AJAX
  registerAjaxCommentRecord();
  
  setUpCheckRequest();
  setUpCheckStorageRetrievalRequest();
  
  /* --- LIGHTBOX --- */
  // Cite lightbox
  $('#cite-record').click(function() {
    var params = extractClassParams(this);
    return Lightbox.get(params['controller'], 'Cite', {id:id});
  });
  // Mail lightbox
  $('#mail-record').click(function() {
    var params = extractClassParams(this);
    return Lightbox.get(params['controller'], 'Email', {id:id});
  });
  // Place a Hold
  $('.placehold').click(function() {
    var params = deparam($(this).attr('href'));
    params.hashKey = params.hashKey.split('#')[0]; // Remove #tabnav
    params.id = id;
    return Lightbox.get('Record', 'Hold', params, {}, function(html) {
      Lightbox.checkForError(html, Lightbox.changeContent);
    });
  });
  // Place a Storage Hold
  $('.placeStorageRetrievalRequest').click(function() {
    var params = deparam($(this).attr('href'));
    params.hashKey = params.hashKey.split('#')[0]; // Remove #tabnav
    Lightbox.addCloseAction(function(op) {
      document.location.href = path+'/MyResearch/Holds';
    });
    return Lightbox.get('Record', 'StorageRetrievalRequest', params, {});
  });
  // Save lightbox
  $('#save-record').click(function() {
    var params = extractClassParams(this);
    return Lightbox.get(params['controller'], 'Save', {id:id});
  });
  // SMS lightbox
  $('#sms-record').click(function() {
    var params = extractClassParams(this);
    return Lightbox.get(params['controller'], 'SMS', {id:id});
  });
  // Tag lightbox
  $('#tagRecord').click(function() {
    var id = $('.hiddenId')[0].value;
    var parts = this.href.split('/');
    Lightbox.addCloseAction(function() {
      var recordId = $('#record_id').val();
      var recordSource = $('.hiddenSource').val();
      
      // Update tag list (add tag)
      var tagList = $('#tagList');
      if (tagList.length > 0) {
        tagList.empty();
        var url = path + '/AJAX/JSON?' + $.param({method:'getRecordTags',id:recordId,'source':recordSource});
        $.ajax({
          dataType: 'json',
          url: url,
          success: function(response) {
            if (response.status == 'OK') {
              $.each(response.data, function(i, tag) {
                var href = path + '/Tag?' + $.param({lookfor:tag.tag});
                var html = (i>0 ? ', ' : ' ') + '<a href="' + htmlEncode(href) + '">' + htmlEncode(tag.tag) +'</a> (' + htmlEncode(tag.cnt) + ')';
                tagList.append(html);
              });
            } else if (response.data && response.data.length > 0) {
              tagList.append(response.data);
            }
          }
        });
      }
    });
    return Lightbox.get(parts[parts.length-3],'AddTag',{id:id});
  });
  // Form handlers
  Lightbox.addFormCallback('saveRecord', function(){Lightbox.confirm(vufindString['bulk_save_success']);});
  Lightbox.addFormCallback('smsRecord', function(){Lightbox.confirm(vufindString['sms_success']);});
  Lightbox.addFormCallback('emailRecord', function(){
    Lightbox.confirm(vufindString['bulk_email_success']);
  });
  Lightbox.addFormCallback('placeHold', function() {
    document.location.href = path+'/MyResearch/Holds';
  });
});
