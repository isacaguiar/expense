package com.novemax.expense.controller;

import com.novemax.expense.dto.GroupDTO;
import com.novemax.expense.service.GroupService;
import io.swagger.v3.oas.annotations.tags.Tag;
import jakarta.validation.Valid;
import lombok.RequiredArgsConstructor;
import org.springframework.http.ResponseEntity;
import org.springframework.security.core.annotation.AuthenticationPrincipal;
import org.springframework.security.core.userdetails.UserDetails;
import org.springframework.web.bind.annotation.*;

import java.util.List;
import java.util.UUID;

@RestController
@RequestMapping("/groups")
@RequiredArgsConstructor
@Tag(name = "Group")
public class GroupController {

  private final GroupService groupService;

  @PostMapping
  public ResponseEntity<GroupDTO> createGroup(@AuthenticationPrincipal UserDetails user,
                                              @Valid @RequestBody GroupDTO groupDTO) {
    return ResponseEntity.ok(groupService.createGroup(user.getUsername(), groupDTO));
  }

  @GetMapping
  public ResponseEntity<List<GroupDTO>> getUserGroups(@AuthenticationPrincipal UserDetails user) {
    return ResponseEntity.ok(groupService.getGroupsByUser(user.getUsername()));
  }

  @GetMapping("/{id}")
  public ResponseEntity<GroupDTO> getGroupById(@PathVariable UUID id,
                                               @AuthenticationPrincipal UserDetails user) {
    return ResponseEntity.ok(groupService.getGroupById(user.getUsername(), id));
  }

  @PutMapping("/{id}")
  public ResponseEntity<GroupDTO> updateGroup(@PathVariable UUID id,
                                              @AuthenticationPrincipal UserDetails user,
                                              @Valid @RequestBody GroupDTO groupDTO) {
    return ResponseEntity.ok(groupService.updateGroup(user.getUsername(), id, groupDTO));
  }

  @DeleteMapping("/{id}")
  public ResponseEntity<Void> deleteGroup(@PathVariable UUID id,
                                          @AuthenticationPrincipal UserDetails user) {
    groupService.deleteGroup(user.getUsername(), id);
    return ResponseEntity.noContent().build();
  }

//  @PostMapping("/{groupId}/members")
//  public ResponseEntity<Void> addUserToGroup(@PathVariable UUID groupId,
//                                             @RequestBody UserEmailDTO userEmail) {
//    groupService.addUserToGroup(groupId, userEmail.getEmail());
//    return ResponseEntity.ok().build();
//  }

//  @PostMapping("/{groupId}/add-members")
//  public ResponseEntity<Void> addUsersToGroup(@PathVariable UUID groupId,
//                                              @Valid @RequestBody AddMembersRequestDTO request) {
//    groupService.addUsersToGroup(groupId, request.getEmails());
//    return ResponseEntity.ok().build();
//  }
}
